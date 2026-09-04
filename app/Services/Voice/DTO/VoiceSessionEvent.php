<?php

namespace App\Services\Voice\DTO;

use App\Enums\VoiceSessionEventType;
use App\Enums\VoiceSessionStatus;

final readonly class VoiceSessionEvent
{
    /**
     * Client-safe payload only. No secrets, prompts, tool JSON, or stack traces.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public VoiceSessionEventType $type,
        public array $payload = [],
        public ?VoiceSessionStatus $state = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = $this->sanitize($this->payload);

        return [
            'type' => $this->type->value,
            'state' => $this->state?->value,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitize(array $payload): array
    {
        unset(
            $payload['api_key'],
            $payload['secret'],
            $payload['token'],
            $payload['system_prompt'],
            $payload['tools'],
            $payload['tool_json'],
            $payload['stack'],
            $payload['exception'],
            $payload['raw'],
        );

        return $payload;
    }
}
