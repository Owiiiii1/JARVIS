<?php

namespace App\Services\Synthesis\DTO;

use App\Enums\SynthesisType;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class SynthesisScope
{
    public function __construct(
        public User $user,
        public SynthesisType $type,
        public ?int $projectId = null,
        public ?string $projectName = null,
        public ?int $entityId = null,
        public ?string $personName = null,
        public int $windowDays = 7,
        public string $commitmentMode = 'all',
        public bool $withNarrative = true,
        public bool $skipCache = false,
        public ?CarbonImmutable $now = null,
    ) {}

    public function now(): CarbonImmutable
    {
        return ($this->now ?? CarbonImmutable::now('UTC'))->utc();
    }

    public function cacheKeyParts(): string
    {
        return implode('|', [
            $this->type->value,
            (string) ($this->projectId ?? ''),
            (string) ($this->projectName ?? ''),
            (string) ($this->entityId ?? ''),
            (string) ($this->personName ?? ''),
            (string) $this->windowDays,
            $this->commitmentMode,
            $this->now?->toIso8601String() ?? '',
        ]);
    }
}
