<?php

namespace App\Services\Voice;

use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Models\Message;
use App\Models\VoiceSession;
use App\Services\Conversations\ChannelContext;
use App\Services\Conversations\ConversationTurnService;
use App\Services\Voice\Exceptions\VoiceException;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Throwable;

final class ElevenLabsRealtimeTurnAdapter
{
    public function __construct(
        private readonly ConversationTurnService $turns,
        private readonly ElevenLabsRealtimeSessionService $sessions,
        private readonly VoiceMetricsLogger $metrics,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, text: string, persisted: bool}
     */
    public function complete(VoiceSession $session, array $payload): array
    {
        $started = microtime(true);
        $text = $this->lastUserText($payload);

        if ($text === '') {
            return [
                'id' => 'chatcmpl-'.$session->public_id,
                'text' => '',
                'persisted' => false,
            ];
        }

        $session->loadMissing(['user', 'conversation']);
        $user = $session->user;
        $conversation = $session->conversation;

        if ($user === null || $conversation === null) {
            throw VoiceException::realtimeUnauthorized();
        }

        $clientMessageId = $this->idempotencyKey($session, $conversation->id, $text);
        $coreStarted = microtime(true);

        try {
            $turn = $this->turns->handleUserMessage(
                $user,
                $conversation,
                $text,
                new ChannelContext(
                    channel: MessageChannel::Web,
                    channelMessageId: $clientMessageId,
                    metadata: [
                        'modality' => 'voice',
                        'voice_mode' => ElevenLabsRealtimeSessionService::VOICE_MODE,
                        'voice_session_id' => $session->id,
                        'voice_session_public_id' => $session->public_id,
                        'origin' => $session->origin->value,
                        'provider' => ElevenLabsRealtimeSessionService::PROVIDER,
                    ],
                ),
            );
        } catch (AuthorizationException) {
            throw VoiceException::realtimeUnauthorized();
        } catch (InvalidArgumentException) {
            return [
                'id' => 'chatcmpl-'.$session->public_id,
                'text' => '',
                'persisted' => false,
            ];
        } catch (Throwable) {
            throw VoiceException::runtimeFailed();
        }

        $coreMs = (int) round((microtime(true) - $coreStarted) * 1000);

        if ($turn->assistantMessage !== null) {
            $this->tagAssistant($turn->assistantMessage, $session);
        }

        $reply = trim((string) ($turn->replyText() ?? ''));
        $this->sessions->rememberTurn($session, [
            'inbound_id' => $turn->inbound->id,
            'assistant_id' => $turn->assistantMessage?->id,
            'error' => $turn->errorText,
        ], [
            'transcript_received_ms' => (int) round((microtime(true) - $started) * 1000) - $coreMs,
            'core_first_output_ms' => $coreMs,
            'core_complete_ms' => $coreMs,
            'total_turn_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        $this->metrics->record('realtime.turn.complete', [
            'session_public_id' => $session->public_id,
            'conversation_id' => $session->conversation_id,
            'core_complete_ms' => $coreMs,
            'total_turn_ms' => (int) round((microtime(true) - $started) * 1000),
            'duplicate' => ! $turn->created,
        ]);

        return [
            'id' => 'chatcmpl-'.$clientMessageId,
            'text' => $reply,
            'persisted' => true,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunks
     */
    public function streamSse(string $completionId, string $text, callable $write): void
    {
        $created = now()->getTimestamp();
        $model = 'jarvis-core';

        if ($text === '') {
            $write($this->chunk($completionId, $created, $model, ['role' => 'assistant'], null));
            $write($this->chunk($completionId, $created, $model, [], 'stop'));
            $write('[DONE]');

            return;
        }

        $first = true;

        foreach ($this->splitText($text) as $piece) {
            $delta = $first
                ? ['role' => 'assistant', 'content' => $piece]
                : ['content' => $piece];
            $first = false;
            $write($this->chunk($completionId, $created, $model, $delta, null));
        }

        $write($this->chunk($completionId, $created, $model, [], 'stop'));
        $write('[DONE]');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function lastUserText(array $payload): string
    {
        $messages = $payload['messages'] ?? [];

        if (! is_array($messages)) {
            return '';
        }

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];

            if (! is_array($message)) {
                continue;
            }

            if (($message['role'] ?? '') !== 'user') {
                continue;
            }

            return $this->stringifyContent($message['content'] ?? '');
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sessionTokenFromPayload(array $payload): string
    {
        $extra = [];

        foreach (['elevenlabs_extra_body', 'custom_llm_extra_body', 'extra_body'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $extra = $payload[$key];
                break;
            }
        }

        $token = trim((string) ($extra['jarvis_session_token'] ?? ''));

        return $token;
    }

    /**
     * @return list<string>
     */
    private function splitText(string $text): array
    {
        $parts = preg_split('/(.{1,48}(?:\s+|$))/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts) || $parts === []) {
            return [$text];
        }

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  array<string, mixed>  $delta
     * @return array<string, mixed>
     */
    private function chunk(string $id, int $created, string $model, array $delta, ?string $finish): array
    {
        return [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => $delta,
                'finish_reason' => $finish,
            ]],
        ];
    }

    private function stringifyContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $item) {
            if (is_string($item)) {
                $parts[] = $item;

                continue;
            }

            if (is_array($item) && is_string($item['text'] ?? null)) {
                $parts[] = $item['text'];
            }
        }

        return trim(implode('', $parts));
    }

    private function idempotencyKey(VoiceSession $session, int $conversationId, string $text): string
    {
        $previousId = (int) Message::query()
            ->where('conversation_id', $conversationId)
            ->where('role', MessageRole::User)
            ->orderByDesc('id')
            ->value('id');

        return substr(hash('sha256', $session->public_id.'|'.$previousId.'|'.$text), 0, 32);
    }

    private function tagAssistant(Message $message, VoiceSession $session): void
    {
        $meta = is_array($message->metadata) ? $message->metadata : [];
        $meta['modality'] = 'voice';
        $meta['voice_mode'] = ElevenLabsRealtimeSessionService::VOICE_MODE;
        $meta['voice_session_id'] = $session->id;
        $meta['voice_session_public_id'] = $session->public_id;
        $message->metadata = $meta;
        $message->save();
    }
}
