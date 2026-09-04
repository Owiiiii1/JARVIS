<?php

namespace App\Services\Voice;

use App\Enums\ConversationKind;
use App\Enums\MessageChannel;
use App\Enums\VoiceOrigin;
use App\Enums\VoiceSessionEventType;
use App\Enums\VoiceSessionStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\VoiceSession;
use App\Services\Conversations\ChannelContext;
use App\Services\Conversations\ConversationTurnService;
use App\Services\Conversations\MessageHistoryService;
use App\Services\Users\UserCapability;
use App\Services\Voice\DTO\SynthesizedSpeech;
use App\Services\Voice\DTO\VoiceAudioChunk;
use App\Services\Voice\DTO\VoiceSessionEvent;
use App\Services\Voice\DTO\VoiceSessionSnapshot;
use App\Services\Voice\Exceptions\VoiceException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class VoiceRuntimeService
{
    public function __construct(
        private readonly ConversationTurnService $turns,
        private readonly MessageHistoryService $history,
        private readonly SpeechToTextManager $stt,
        private readonly TextToSpeechManager $tts,
        private readonly VoiceSessionStateMachine $states,
        private readonly VoiceTempAudioStore $audio,
        private readonly VoiceSettingsService $settings,
        private readonly VoiceMetricsLogger $metrics,
    ) {}

    public function start(User $user, Conversation $conversation, VoiceOrigin $origin = VoiceOrigin::Web): VoiceSessionSnapshot
    {
        if (! $user->canUseCapability(UserCapability::VOICE)) {
            throw VoiceException::forbidden();
        }

        if (! $user->isActive()) {
            throw VoiceException::forbidden();
        }

        $this->assertOwnedConversation($user, $conversation);
        $this->assertNotGroup($conversation);
        $this->endExpiredForUser($user);

        $open = VoiceSession::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', [VoiceSessionStatus::Ended->value])
            ->count();

        $max = max(1, (int) config('voice.max_sessions_per_user', 2));

        if ($open >= $max) {
            throw VoiceException::limitReached();
        }

        $effective = $this->settings->effective();

        $session = VoiceSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'origin' => $origin,
            'status' => VoiceSessionStatus::Connecting,
            'stt_provider' => $effective->sttProvider->value,
            'tts_provider' => $effective->ttsProvider->value,
            'started_at' => now(),
            'last_activity_at' => now(),
            'metadata' => [
                'interrupt_count' => 0,
                'latency' => [],
                'events' => [],
            ],
        ]);

        $this->states->transition($session, VoiceSessionStatus::Idle);
        $events = [
            $this->event(VoiceSessionEventType::SessionStarted, $session->status),
            $this->event(VoiceSessionEventType::StateChanged, $session->status, [
                'from' => VoiceSessionStatus::Connecting->value,
                'to' => VoiceSessionStatus::Idle->value,
            ]),
        ];
        $this->rememberEvents($session, $events);

        $this->metrics->record('session.started', [
            'session_id' => $session->id,
            'session_public_id' => $session->public_id,
            'origin' => $origin->value,
            'stt_provider' => $session->stt_provider,
            'tts_provider' => $session->tts_provider,
        ]);

        return VoiceSessionSnapshot::fromSession($session, $this->eventsToArray($events));
    }

    public function snapshot(User $user, VoiceSession $session): VoiceSessionSnapshot
    {
        $session = $this->ownedOpenOrEnded($user, $session);
        $this->touchIfOpen($session);

        return VoiceSessionSnapshot::fromSession($session, $this->storedEvents($session));
    }

    public function listen(User $user, VoiceSession $session): VoiceSessionSnapshot
    {
        $session = $this->ownedActive($user, $session);
        $from = $session->status;
        $this->states->transition($session, VoiceSessionStatus::Listening);
        $events = [
            $this->event(VoiceSessionEventType::StateChanged, $session->status, [
                'from' => $from->value,
                'to' => VoiceSessionStatus::Listening->value,
            ]),
            $this->event(VoiceSessionEventType::ListeningStarted, $session->status),
        ];
        $this->rememberEvents($session, $events);
        $this->metrics->record('state.changed', $this->sessionContext($session, [
            'from' => $from->value,
            'to' => $session->status->value,
        ]));

        return VoiceSessionSnapshot::fromSession($session, $this->eventsToArray($events));
    }

    public function processUtterance(
        User $user,
        VoiceSession $session,
        UploadedFile $file,
        int $sequence,
        bool $isFinal,
        string $clientMessageId,
        ?int $sampleRate = null,
        int $channels = 1,
        ?int $durationMs = null,
        ?string $language = null,
    ): VoiceSessionSnapshot {
        $session = $this->ownedActive($user, $session);

        if (in_array($session->status, [VoiceSessionStatus::Muted, VoiceSessionStatus::Thinking, VoiceSessionStatus::Transcribing], true)) {
            throw VoiceException::invalidState($session->status->value, VoiceSessionStatus::Transcribing->value);
        }

        if (in_array($session->status, [VoiceSessionStatus::Idle, VoiceSessionStatus::Interrupted, VoiceSessionStatus::Speaking], true)) {
            $from = $session->status;

            if ($from === VoiceSessionStatus::Speaking) {
                $this->markPlaybackInterrupted($session);
            }

            $this->states->transition($session, VoiceSessionStatus::Listening);
            $this->rememberEvents($session, [
                $this->event(VoiceSessionEventType::StateChanged, $session->status, [
                    'from' => $from->value,
                    'to' => VoiceSessionStatus::Listening->value,
                ]),
            ]);
        }

        $chunk = $this->audio->store(
            (string) $session->public_id,
            (int) $user->id,
            $sequence,
            $file,
            $isFinal,
            $sampleRate,
            $channels,
            $durationMs,
        );

        $this->metrics->record('audio.received', $this->sessionContext($session, [
            'audio_bytes' => $chunk->byteLength,
            'duration_ms' => $chunk->durationMs,
            'mime' => $chunk->mime,
            'sequence' => $chunk->sequence,
            'is_final' => $chunk->isFinal,
        ]));

        if (! $chunk->isFinal) {
            return VoiceSessionSnapshot::fromSession($session, $this->eventsToArray([
                $this->event(VoiceSessionEventType::StateChanged, $session->status, [
                    'queued' => true,
                    'sequence' => $chunk->sequence,
                ]),
            ]));
        }

        return $this->runFinalUtterance($user, $session, $chunk, $clientMessageId, $language);
    }

    public function interrupt(User $user, VoiceSession $session): VoiceSessionSnapshot
    {
        $session = $this->ownedActive($user, $session);
        $from = $session->status;

        if (! $from->canTransitionTo(VoiceSessionStatus::Interrupted) && $from !== VoiceSessionStatus::Interrupted) {
            throw VoiceException::invalidState($from->value, VoiceSessionStatus::Interrupted->value);
        }

        $this->states->transition($session, VoiceSessionStatus::Interrupted);
        $this->markPlaybackInterrupted($session);

        $meta = $session->meta();
        $meta['interrupt_count'] = (int) ($meta['interrupt_count'] ?? 0) + 1;
        $session->metadata = $meta;
        $session->save();

        $events = [
            $this->event(VoiceSessionEventType::Interrupted, $session->status, [
                'from' => $from->value,
            ]),
            $this->event(VoiceSessionEventType::StateChanged, $session->status, [
                'from' => $from->value,
                'to' => VoiceSessionStatus::Interrupted->value,
            ]),
        ];
        $this->rememberEvents($session, $events);
        $this->metrics->record('interrupted', $this->sessionContext($session, [
            'from' => $from->value,
            'interrupt_count' => $meta['interrupt_count'],
        ]));

        return VoiceSessionSnapshot::fromSession($session, $this->eventsToArray($events));
    }

    public function mute(User $user, VoiceSession $session): VoiceSessionSnapshot
    {
        $session = $this->ownedActive($user, $session);
        $from = $session->status;
        $this->states->transition($session, VoiceSessionStatus::Muted);
        $events = [
            $this->event(VoiceSessionEventType::Muted, $session->status),
            $this->event(VoiceSessionEventType::StateChanged, $session->status, [
                'from' => $from->value,
                'to' => VoiceSessionStatus::Muted->value,
            ]),
        ];
        $this->rememberEvents($session, $events);
        $this->metrics->record('muted', $this->sessionContext($session, ['from' => $from->value]));

        return VoiceSessionSnapshot::fromSession($session, $this->eventsToArray($events));
    }

    public function resume(User $user, VoiceSession $session): VoiceSessionSnapshot
    {
        $session = $this->ownedActive($user, $session);
        $from = $session->status;
        $this->states->transition($session, VoiceSessionStatus::Idle);
        $events = [
            $this->event(VoiceSessionEventType::Resumed, $session->status),
            $this->event(VoiceSessionEventType::StateChanged, $session->status, [
                'from' => $from->value,
                'to' => VoiceSessionStatus::Idle->value,
            ]),
        ];
        $this->rememberEvents($session, $events);
        $this->metrics->record('resumed', $this->sessionContext($session, ['from' => $from->value]));

        return VoiceSessionSnapshot::fromSession($session, $this->eventsToArray($events));
    }

    public function end(User $user, VoiceSession $session): VoiceSessionSnapshot
    {
        $session = $this->ownedSession($user, $session);

        if ($session->status !== VoiceSessionStatus::Ended) {
            $from = $session->status;
            $this->states->transition($session, VoiceSessionStatus::Ended);
            $events = [
                $this->event(VoiceSessionEventType::StateChanged, $session->status, [
                    'from' => $from->value,
                    'to' => VoiceSessionStatus::Ended->value,
                ]),
                $this->event(VoiceSessionEventType::SessionEnded, $session->status),
            ];
            $this->rememberEvents($session, $events);
            $this->metrics->record('session.ended', $this->sessionContext($session, ['from' => $from->value]));
        }

        return VoiceSessionSnapshot::fromSession($session, $this->storedEvents($session));
    }

    public function expireStale(): int
    {
        $ttl = max(60, (int) config('voice.session_ttl_seconds', 3600));
        $idle = max(30, (int) config('voice.inactivity_timeout_seconds', 300));
        $count = 0;

        $open = VoiceSession::query()
            ->whereNotIn('status', [VoiceSessionStatus::Ended->value])
            ->get();

        foreach ($open as $session) {
            if ($this->isExpired($session, $ttl, $idle)) {
                $this->forceEnd($session, 'voice_session_expired');
                $count++;
            }
        }

        return $count;
    }

    private function runFinalUtterance(
        User $user,
        VoiceSession $session,
        VoiceAudioChunk $chunk,
        string $clientMessageId,
        ?string $language,
    ): VoiceSessionSnapshot {
        $events = [];
        $from = $session->status;
        $this->states->transition($session, VoiceSessionStatus::Transcribing);
        $events[] = $this->event(VoiceSessionEventType::StateChanged, $session->status, [
            'from' => $from->value,
            'to' => VoiceSessionStatus::Transcribing->value,
        ]);

        $latency = $session->meta()['latency'] ?? [];
        $latency['capture_complete_at'] = now()->toIso8601String();
        $sttStarted = microtime(true);

        try {
            $transcript = $this->stt->transcribe($chunk, $language);
            $this->audio->delete($chunk);
        } catch (VoiceException $exception) {
            $this->metrics->record('stt.failed', $this->sessionContext($session, [
                'error_code' => $exception->error,
                'stt_latency_ms' => (int) round((microtime(true) - $sttStarted) * 1000),
                'audio_bytes' => $chunk->byteLength,
            ]));

            $this->failOpenToListening($session, $exception, $events);

            return VoiceSessionSnapshot::fromSession($session, $this->eventsToArray($events));
        }

        $latency['stt_final_at'] = now()->toIso8601String();
        $this->metrics->record('stt.final', $this->sessionContext($session, [
            'stt_latency_ms' => (int) round((microtime(true) - $sttStarted) * 1000),
            'audio_bytes' => $chunk->byteLength,
            'stt_provider' => $this->stt->providerName(),
        ]));

        $text = trim($transcript->text);

        if ($text === '') {
            $this->states->transition($session, VoiceSessionStatus::Listening);
            $events[] = $this->event(VoiceSessionEventType::StateChanged, $session->status, [
                'from' => VoiceSessionStatus::Transcribing->value,
                'to' => VoiceSessionStatus::Listening->value,
            ]);
            $this->rememberEvents($session, $events);

            return VoiceSessionSnapshot::fromSession($session, $this->eventsToArray($events));
        }

        $events[] = $this->event(VoiceSessionEventType::TranscriptFinal, $session->status, [
            'text' => $text,
            'language' => $transcript->language,
            'confidence' => $transcript->confidence,
        ]);

        $this->states->transition($session, VoiceSessionStatus::Thinking);
        $events[] = $this->event(VoiceSessionEventType::AssistantThinking, $session->status);
        $events[] = $this->event(VoiceSessionEventType::StateChanged, $session->status, [
            'from' => VoiceSessionStatus::Transcribing->value,
            'to' => VoiceSessionStatus::Thinking->value,
        ]);
        $this->writeLatency($session, $latency);

        $aiStarted = microtime(true);
        $latency['ai_start_at'] = now()->toIso8601String();

        try {
            $turn = $this->turns->handleUserMessage(
                $user,
                $session->conversation,
                $text,
                new ChannelContext(
                    channel: MessageChannel::Web,
                    channelMessageId: $clientMessageId,
                    metadata: [
                        'modality' => 'voice',
                        'voice_session_id' => $session->id,
                        'voice_session_public_id' => $session->public_id,
                        'origin' => $session->origin->value,
                    ],
                ),
            );
        } catch (AuthorizationException) {
            $this->failSession($session, VoiceException::runtimeFailed(), $events);
            throw VoiceException::runtimeFailed();
        } catch (InvalidArgumentException $exception) {
            $this->failOpenToListening($session, VoiceException::runtimeFailed(), $events);

            return VoiceSessionSnapshot::fromSession($session, $this->eventsToArray($events));
        } catch (Throwable) {
            $this->failSession($session, VoiceException::runtimeFailed(), $events);
            throw VoiceException::runtimeFailed();
        }

        $latency['ai_complete_at'] = now()->toIso8601String();
        $this->metrics->record('ai.complete', $this->sessionContext($session, [
            'ai_latency_ms' => (int) round((microtime(true) - $aiStarted) * 1000),
        ]));

        if ($turn->assistantMessage !== null) {
            $this->tagAssistantMessage($turn->assistantMessage, $session);
        }

        $assistantText = $turn->replyText();
        $turnPayload = [
            'inbound' => $this->history->toArray($turn->inbound),
            'assistant' => $turn->assistantMessage !== null
                ? $this->history->toArray($turn->assistantMessage)
                : null,
            'error' => $turn->errorText,
            'duplicate' => ! $turn->created,
        ];

        if (is_string($assistantText) && $assistantText !== '') {
            $events[] = $this->event(VoiceSessionEventType::AssistantText, $session->status, [
                'text' => $assistantText,
            ]);
        }

        $audioBase64 = null;
        $audioMime = null;

        if (is_string($assistantText) && trim($assistantText) !== '') {
            $speech = $this->maybeSynthesize($session, $assistantText, $latency, $events);
            if ($speech !== null) {
                $audioBase64 = base64_encode($speech->bytes);
                $audioMime = $speech->mime;
            }
        } else {
            $this->states->transition($session, VoiceSessionStatus::Listening);
            $events[] = $this->event(VoiceSessionEventType::StateChanged, $session->status, [
                'from' => VoiceSessionStatus::Thinking->value,
                'to' => VoiceSessionStatus::Listening->value,
            ]);
        }

        $this->writeLatency($session, $latency);
        $this->rememberEvents($session, $events);

        return VoiceSessionSnapshot::fromSession(
            $session,
            $this->eventsToArray($events),
            $turnPayload,
            $audioBase64,
            $audioMime,
        );
    }

    /**
     * @param  list<VoiceSessionEvent>  $events
     */
    private function maybeSynthesize(VoiceSession $session, string $text, array &$latency, array &$events): ?SynthesizedSpeech
    {
        $limit = max(80, (int) config('voice.max_text_for_tts', 2000));
        $spoken = mb_substr(trim($text), 0, $limit);

        if (! $this->tts->isConfigured()) {
            $this->states->transition($session, VoiceSessionStatus::Listening);
            $events[] = $this->event(VoiceSessionEventType::Error, $session->status, [
                'code' => 'voice_tts_not_configured',
            ]);
            $events[] = $this->event(VoiceSessionEventType::StateChanged, $session->status, [
                'from' => VoiceSessionStatus::Thinking->value,
                'to' => VoiceSessionStatus::Listening->value,
            ]);

            return null;
        }

        $this->states->transition($session, VoiceSessionStatus::Speaking);
        $events[] = $this->event(VoiceSessionEventType::AudioStarted, $session->status);
        $events[] = $this->event(VoiceSessionEventType::StateChanged, $session->status, [
            'from' => VoiceSessionStatus::Thinking->value,
            'to' => VoiceSessionStatus::Speaking->value,
        ]);
        $latency['tts_start_at'] = now()->toIso8601String();
        $ttsStarted = microtime(true);

        try {
            $speech = $this->tts->synthesize($spoken);
        } catch (VoiceException $exception) {
            $this->metrics->record('tts.failed', $this->sessionContext($session, [
                'error_code' => $exception->error,
                'tts_latency_ms' => (int) round((microtime(true) - $ttsStarted) * 1000),
            ]));
            $this->states->transition($session, VoiceSessionStatus::Listening);
            $events[] = $this->event(VoiceSessionEventType::Error, $session->status, [
                'code' => $exception->error,
            ]);
            $events[] = $this->event(VoiceSessionEventType::StateChanged, $session->status, [
                'from' => VoiceSessionStatus::Speaking->value,
                'to' => VoiceSessionStatus::Listening->value,
            ]);

            return null;
        }

        $latency['tts_complete_at'] = now()->toIso8601String();
        $this->metrics->record('tts.complete', $this->sessionContext($session, [
            'tts_latency_ms' => (int) round((microtime(true) - $ttsStarted) * 1000),
            'audio_bytes' => strlen($speech->bytes),
            'tts_provider' => $this->tts->providerName(),
        ]));

        $events[] = $this->event(VoiceSessionEventType::AudioEnded, $session->status, [
            'mime' => $speech->mime,
            'duration_seconds' => $speech->durationSeconds,
        ]);

        $this->states->transition($session, VoiceSessionStatus::Listening);
        $events[] = $this->event(VoiceSessionEventType::StateChanged, $session->status, [
            'from' => VoiceSessionStatus::Speaking->value,
            'to' => VoiceSessionStatus::Listening->value,
        ]);
        $events[] = $this->event(VoiceSessionEventType::ListeningStarted, $session->status);

        return $speech;
    }

    /**
     * @param  list<VoiceSessionEvent>  $events
     */
    private function failOpenToListening(VoiceSession $session, VoiceException $exception, array &$events): void
    {
        if ($session->status === VoiceSessionStatus::Transcribing) {
            $this->states->transition($session, VoiceSessionStatus::Listening);
        } elseif ($session->status->canTransitionTo(VoiceSessionStatus::Listening)) {
            $this->states->transition($session, VoiceSessionStatus::Listening);
        }

        $events[] = $this->event(VoiceSessionEventType::Error, $session->status, [
            'code' => $exception->error,
        ]);
        $this->rememberEvents($session, $events);
        $session->error_code = $exception->error;
        $session->save();
    }

    /**
     * @param  list<VoiceSessionEvent>  $events
     */
    private function failSession(VoiceSession $session, VoiceException $exception, array &$events): void
    {
        if ($session->status->canTransitionTo(VoiceSessionStatus::Error)) {
            $this->states->transition($session, VoiceSessionStatus::Error);
        }

        $session->error_code = $exception->error;
        $session->save();
        $events[] = $this->event(VoiceSessionEventType::Error, $session->status, [
            'code' => $exception->error,
        ]);
        $this->rememberEvents($session, $events);
        $this->metrics->record('error', $this->sessionContext($session, [
            'error_code' => $exception->error,
        ]));
    }

    private function tagAssistantMessage(Message $message, VoiceSession $session): void
    {
        $meta = is_array($message->metadata) ? $message->metadata : [];
        $meta['modality'] = 'voice';
        $meta['voice_session_id'] = $session->id;
        $meta['voice_session_public_id'] = $session->public_id;
        $message->metadata = $meta;
        $message->save();
    }

    private function markPlaybackInterrupted(VoiceSession $session): void
    {
        $message = Message::query()
            ->where('conversation_id', $session->conversation_id)
            ->where('metadata->voice_session_public_id', $session->public_id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->first();

        if ($message === null) {
            return;
        }

        $meta = is_array($message->metadata) ? $message->metadata : [];
        $meta['voice_playback_interrupted'] = true;
        $message->metadata = $meta;
        $message->save();
    }

    private function ownedActive(User $user, VoiceSession $session): VoiceSession
    {
        $session = $this->ownedSession($user, $session);
        $this->assertNotExpired($session);

        if ($session->status === VoiceSessionStatus::Ended) {
            throw VoiceException::expired();
        }

        return $session;
    }

    private function ownedOpenOrEnded(User $user, VoiceSession $session): VoiceSession
    {
        return $this->ownedSession($user, $session);
    }

    private function ownedSession(User $user, VoiceSession $session): VoiceSession
    {
        if ((int) $session->user_id !== (int) $user->id) {
            throw VoiceException::notFound();
        }

        $session->loadMissing('conversation');

        if ($session->conversation === null || (int) $session->conversation->user_id !== (int) $user->id) {
            throw VoiceException::notFound();
        }

        return $session;
    }

    private function assertOwnedConversation(User $user, Conversation $conversation): void
    {
        if ((int) $conversation->user_id !== (int) $user->id) {
            throw VoiceException::notFound();
        }
    }

    private function assertNotGroup(Conversation $conversation): void
    {
        if ($conversation->kind !== ConversationKind::Personal) {
            throw VoiceException::runtimeFailed();
        }
    }

    private function assertNotExpired(VoiceSession $session): void
    {
        $ttl = max(60, (int) config('voice.session_ttl_seconds', 3600));
        $idle = max(30, (int) config('voice.inactivity_timeout_seconds', 300));

        if ($this->isExpired($session, $ttl, $idle)) {
            $this->forceEnd($session, 'voice_session_expired');
            throw VoiceException::expired();
        }
    }

    private function isExpired(VoiceSession $session, int $ttl, int $idle): bool
    {
        if ($session->status === VoiceSessionStatus::Ended) {
            return false;
        }

        if ($session->started_at !== null && $session->started_at->lte(now()->subSeconds($ttl))) {
            return true;
        }

        $activity = $session->last_activity_at ?? $session->started_at;

        return $activity !== null && $activity->lte(now()->subSeconds($idle));
    }

    private function forceEnd(VoiceSession $session, string $code): void
    {
        if ($session->status->canTransitionTo(VoiceSessionStatus::Ended)) {
            $this->states->transition($session, VoiceSessionStatus::Ended);
        } else {
            $session->status = VoiceSessionStatus::Ended;
            $session->ended_at = now();
            $session->save();
        }

        $session->error_code = $code;
        $session->save();
        $this->metrics->record('session.expired', $this->sessionContext($session, [
            'error_code' => $code,
        ]));
    }

    private function endExpiredForUser(User $user): void
    {
        $ttl = max(60, (int) config('voice.session_ttl_seconds', 3600));
        $idle = max(30, (int) config('voice.inactivity_timeout_seconds', 300));

        VoiceSession::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', [VoiceSessionStatus::Ended->value])
            ->get()
            ->each(function (VoiceSession $session) use ($ttl, $idle): void {
                if ($this->isExpired($session, $ttl, $idle)) {
                    $this->forceEnd($session, 'voice_session_expired');
                }
            });
    }

    private function touchIfOpen(VoiceSession $session): void
    {
        if ($session->status->isOpen()) {
            $this->assertNotExpired($session);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(VoiceSessionEventType $type, VoiceSessionStatus $state, array $payload = []): VoiceSessionEvent
    {
        return new VoiceSessionEvent($type, $payload, $state);
    }

    /**
     * @param  list<VoiceSessionEvent>  $events
     * @return list<array<string, mixed>>
     */
    private function eventsToArray(array $events): array
    {
        return array_map(static fn (VoiceSessionEvent $event): array => $event->toArray(), $events);
    }

    /**
     * @param  list<VoiceSessionEvent>  $events
     */
    private function rememberEvents(VoiceSession $session, array $events): void
    {
        $meta = $session->meta();
        $stored = is_array($meta['events'] ?? null) ? $meta['events'] : [];

        foreach ($this->eventsToArray($events) as $row) {
            $stored[] = $row;
        }

        $limit = max(8, (int) config('voice.max_events_per_response', 24));
        $meta['events'] = array_slice($stored, -$limit);
        $session->metadata = $meta;
        $session->last_activity_at = now();
        $session->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedEvents(VoiceSession $session): array
    {
        $events = $session->meta()['events'] ?? [];

        return is_array($events) ? $events : [];
    }

    /**
     * @param  array<string, mixed>  $latency
     */
    private function writeLatency(VoiceSession $session, array $latency): void
    {
        $meta = $session->meta();
        $meta['latency'] = $latency;
        $session->metadata = $meta;
        $session->save();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function sessionContext(VoiceSession $session, array $extra = []): array
    {
        return array_merge([
            'session_id' => $session->id,
            'session_public_id' => $session->public_id,
            'status' => $session->status->value,
            'stt_provider' => $session->stt_provider,
            'tts_provider' => $session->tts_provider,
        ], $extra);
    }
}
