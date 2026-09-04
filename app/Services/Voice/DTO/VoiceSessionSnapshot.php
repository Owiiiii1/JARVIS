<?php

namespace App\Services\Voice\DTO;

use App\Enums\VoiceOrigin;
use App\Enums\VoiceSessionStatus;
use App\Models\VoiceSession;

final readonly class VoiceSessionSnapshot
{
    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>|null  $latency
     * @param  array<string, mixed>|null  $turn
     */
    public function __construct(
        public string $publicId,
        public int $conversationId,
        public VoiceOrigin $origin,
        public VoiceSessionStatus $status,
        public ?string $sttProvider,
        public ?string $ttsProvider,
        public ?string $errorCode,
        public array $events = [],
        public ?array $latency = null,
        public ?array $turn = null,
        public ?string $audioBase64 = null,
        public ?string $audioMime = null,
    ) {}

    public static function fromSession(VoiceSession $session, array $events = [], ?array $turn = null, ?string $audioBase64 = null, ?string $audioMime = null): self
    {
        $meta = $session->meta();

        return new self(
            publicId: (string) $session->public_id,
            conversationId: (int) $session->conversation_id,
            origin: $session->origin,
            status: $session->status,
            sttProvider: $session->stt_provider,
            ttsProvider: $session->tts_provider,
            errorCode: $session->error_code,
            events: $events,
            latency: is_array($meta['latency'] ?? null) ? $meta['latency'] : null,
            turn: $turn,
            audioBase64: $audioBase64,
            audioMime: $audioMime,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'public_id' => $this->publicId,
            'conversation_id' => $this->conversationId,
            'origin' => $this->origin->value,
            'status' => $this->status->value,
            'stt_provider' => $this->sttProvider,
            'tts_provider' => $this->ttsProvider,
            'error_code' => $this->errorCode,
            'events' => $this->events,
            'latency' => $this->latency,
            'turn' => $this->turn,
            'audio' => $this->audioBase64 === null ? null : [
                'mime' => $this->audioMime,
                'base64' => $this->audioBase64,
            ],
        ];
    }
}
