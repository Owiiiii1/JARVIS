<?php

namespace App\Services\Voice;

use App\Enums\ConversationKind;
use App\Enums\VoiceOrigin;
use App\Enums\VoiceSessionStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\VoiceSession;
use App\Services\Conversations\MessageHistoryService;
use App\Services\Users\UserCapability;
use App\Services\Voice\Contracts\ResolvesUserVoice;
use App\Services\Voice\Exceptions\VoiceException;
use Illuminate\Support\Str;

final class ElevenLabsRealtimeSessionService
{
    public const PROVIDER = 'elevenlabs_realtime';

    public const VOICE_MODE = 'realtime';

    public function __construct(
        private readonly VoiceSettingsService $settings,
        private readonly ResolvesUserVoice $voices,
        private readonly ElevenLabsRealtimeClient $client,
        private readonly ElevenLabsRealtimeSessionToken $tokens,
        private readonly VoiceSessionStateMachine $states,
        private readonly VoiceMetricsLogger $metrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function workspacePayload(): array
    {
        $configured = $this->isConfigured();

        return [
            'enabled' => $this->flagEnabled(),
            'configured' => $configured,
            'default_mode' => 'ptt',
            'fallback_label' => 'Переключиться на Рацию',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminPayload(): array
    {
        $configured = $this->isConfigured();

        return [
            'enabled' => $this->flagEnabled(),
            'configured' => $configured,
            'status' => $configured ? 'configured' : 'not_configured',
            'status_label' => $configured ? 'Configured' : 'Not configured',
            'agent_id_set' => trim((string) config('voice.realtime.agent_id', '')) !== '',
            'custom_llm_secret_set' => trim((string) config('voice.realtime.custom_llm_secret', '')) !== '',
        ];
    }

    public function isConfigured(): bool
    {
        return $this->flagEnabled()
            && trim((string) config('voice.realtime.agent_id', '')) !== ''
            && trim((string) config('voice.realtime.custom_llm_secret', '')) !== ''
            && $this->settings->elevenLabsApiKey() !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function start(User $user, Conversation $conversation): array
    {
        $this->assertCanStart($user, $conversation);

        if (! $this->isConfigured()) {
            throw VoiceException::realtimeNotConfigured();
        }

        $this->endOpenRealtimeForUser($user);
        $this->assertSessionBudget($user);

        $started = microtime(true);
        $session = VoiceSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'origin' => VoiceOrigin::Web,
            'status' => VoiceSessionStatus::Connecting,
            'stt_provider' => null,
            'tts_provider' => 'elevenlabs',
            'started_at' => now(),
            'last_activity_at' => now(),
            'metadata' => [
                'provider' => self::PROVIDER,
                'voice_mode' => self::VOICE_MODE,
                'latency' => [],
                'events' => [],
            ],
        ]);

        try {
            $signedUrl = $this->client->signedConversationUrl(
                trim((string) config('voice.realtime.agent_id', '')),
            );
        } catch (VoiceException $exception) {
            $session->error_code = $exception->error;
            $this->forceEnd($session);
            throw $exception;
        }

        $this->states->transition($session, VoiceSessionStatus::Listening);
        $token = $this->tokens->issue($session->fresh() ?? $session);
        $session = $session->fresh() ?? $session;
        $connectMs = (int) round((microtime(true) - $started) * 1000);
        $this->writeLatency($session, [
            'session_connect_ms' => $connectMs,
        ]);

        $this->metrics->record('realtime.session.started', [
            'session_public_id' => $session->public_id,
            'conversation_id' => $session->conversation_id,
            'session_connect_ms' => $connectMs,
        ]);

        $voiceId = $this->voices->voiceIdFor($user);

        return [
            'public_id' => $session->public_id,
            'conversation_id' => (int) $session->conversation_id,
            'status' => $session->status->value,
            'signed_url' => $signedUrl,
            'adapter_token' => $token,
            'voice_id' => $voiceId,
            'expressive' => (bool) config('voice.realtime.expressive', true),
            'connection_type' => 'websocket',
            'extra_body' => [
                'jarvis_session_token' => $token,
            ],
            'overrides' => [
                'agent' => [
                    'firstMessage' => '',
                ],
                'tts' => [
                    'voiceId' => $voiceId,
                ],
            ],
        ];
    }

    public function end(User $user, VoiceSession $session): VoiceSession
    {
        $session = $this->ownedRealtime($user, $session);

        if ($session->status !== VoiceSessionStatus::Ended) {
            $this->states->transition($session, VoiceSessionStatus::Ended);
            $this->metrics->record('realtime.session.ended', [
                'session_public_id' => $session->public_id,
                'conversation_id' => $session->conversation_id,
            ]);
        }

        return $session->fresh() ?? $session;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordClientMetrics(User $user, VoiceSession $session, array $payload): VoiceSession
    {
        $session = $this->ownedRealtime($user, $session);
        $meta = $session->meta();
        $latency = is_array($meta['latency'] ?? null) ? $meta['latency'] : [];

        foreach (['session_connect_ms', 'first_audio_ms', 'total_turn_ms'] as $key) {
            if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                $latency[$key] = max(0, (int) $payload[$key]);
            }
        }

        $external = trim((string) ($payload['external_conversation_id'] ?? ''));

        if ($external !== '' && strlen($external) <= 128) {
            $meta['external_conversation_id'] = $external;
        }

        $meta['latency'] = $latency;
        $session->metadata = $meta;
        $session->last_activity_at = now();
        $session->save();

        $this->metrics->record('realtime.client_metrics', [
            'session_public_id' => $session->public_id,
            'session_connect_ms' => $latency['session_connect_ms'] ?? null,
            'first_audio_ms' => $latency['first_audio_ms'] ?? null,
            'total_turn_ms' => $latency['total_turn_ms'] ?? null,
        ]);

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    public function turnSnapshot(User $user, VoiceSession $session, MessageHistoryService $history): array
    {
        $session = $this->ownedRealtime($user, $session);
        $turn = $session->meta()['last_turn'] ?? null;

        return [
            'public_id' => $session->public_id,
            'conversation_id' => (int) $session->conversation_id,
            'status' => $session->status->value,
            'ended' => $session->status === VoiceSessionStatus::Ended,
            'turn' => $this->hydrateTurn($turn, $history),
        ];
    }

    public function resolveAdapterSession(string $token): VoiceSession
    {
        $session = $this->tokens->resolve($token);
        $session->loadMissing(['user', 'conversation']);

        $user = $session->user;
        $conversation = $session->conversation;

        if ($user === null || $conversation === null) {
            throw VoiceException::realtimeUnauthorized();
        }

        if (! $user->isActive() || ! $user->canUseCapability(UserCapability::VOICE)) {
            throw VoiceException::realtimeUnauthorized();
        }

        if ((int) $conversation->user_id !== (int) $user->id) {
            throw VoiceException::realtimeUnauthorized();
        }

        if ($conversation->kind !== ConversationKind::Personal) {
            throw VoiceException::realtimeUnauthorized();
        }

        $session->last_activity_at = now();
        $session->save();

        return $session;
    }

    /**
     * @param  array<string, mixed>  $turn
     */
    public function rememberTurn(VoiceSession $session, array $turn, array $latency = []): void
    {
        $meta = $session->meta();
        $existingLatency = is_array($meta['latency'] ?? null) ? $meta['latency'] : [];
        $meta['last_turn'] = [
            'inbound_id' => $turn['inbound_id'] ?? null,
            'assistant_id' => $turn['assistant_id'] ?? null,
            'error' => $turn['error'] ?? null,
            'at' => now()->toIso8601String(),
        ];
        $meta['latency'] = array_merge($existingLatency, $latency);
        $session->metadata = $meta;
        $session->last_activity_at = now();
        $session->save();
    }

    public function endOpenRealtimeForUser(User $user): int
    {
        $open = VoiceSession::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', [VoiceSessionStatus::Ended->value])
            ->get();

        $count = 0;

        foreach ($open as $session) {
            if (! $session->isRealtime()) {
                continue;
            }

            $this->forceEnd($session);
            $count++;
        }

        return $count;
    }

    private function assertCanStart(User $user, Conversation $conversation): void
    {
        if (! $user->canUseCapability(UserCapability::VOICE) || ! $user->isActive()) {
            throw VoiceException::forbidden();
        }

        if ((int) $conversation->user_id !== (int) $user->id) {
            throw VoiceException::notFound();
        }

        if ($conversation->kind !== ConversationKind::Personal) {
            throw VoiceException::runtimeFailed();
        }
    }

    private function assertSessionBudget(User $user): void
    {
        $open = VoiceSession::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', [VoiceSessionStatus::Ended->value])
            ->count();

        $max = max(1, (int) config('voice.max_sessions_per_user', 2));

        if ($open >= $max) {
            throw VoiceException::limitReached();
        }
    }

    private function ownedRealtime(User $user, VoiceSession $session): VoiceSession
    {
        if ((int) $session->user_id !== (int) $user->id || ! $session->isRealtime()) {
            throw VoiceException::notFound();
        }

        $session->loadMissing('conversation');

        if ($session->conversation === null || (int) $session->conversation->user_id !== (int) $user->id) {
            throw VoiceException::notFound();
        }

        return $session;
    }

    private function forceEnd(VoiceSession $session): void
    {
        if ($session->status === VoiceSessionStatus::Ended) {
            return;
        }

        if ($session->status->canTransitionTo(VoiceSessionStatus::Ended)) {
            $this->states->transition($session, VoiceSessionStatus::Ended);

            return;
        }

        $session->status = VoiceSessionStatus::Ended;
        $session->ended_at = now();
        $session->save();
    }

    /**
     * @param  array<string, int>  $latency
     */
    private function writeLatency(VoiceSession $session, array $latency): void
    {
        $meta = $session->meta();
        $existing = is_array($meta['latency'] ?? null) ? $meta['latency'] : [];
        $meta['latency'] = array_merge($existing, $latency);
        $session->metadata = $meta;
        $session->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function hydrateTurn(mixed $turn, MessageHistoryService $history): ?array
    {
        if (! is_array($turn)) {
            return null;
        }

        $inboundId = isset($turn['inbound_id']) ? (int) $turn['inbound_id'] : 0;
        $assistantId = isset($turn['assistant_id']) ? (int) $turn['assistant_id'] : 0;
        $inbound = $inboundId > 0 ? Message::query()->find($inboundId) : null;
        $assistant = $assistantId > 0 ? Message::query()->find($assistantId) : null;

        return [
            'inbound' => $inbound !== null ? $history->toArray($inbound) : null,
            'assistant' => $assistant !== null ? $history->toArray($assistant) : null,
            'error' => is_string($turn['error'] ?? null) ? $turn['error'] : null,
        ];
    }

    private function flagEnabled(): bool
    {
        return (bool) config('voice.realtime.enabled', false);
    }
}
