<?php

namespace App\Services\Watchers\DTO;

final readonly class WatcherMatch
{
    /**
     * @param  array<string, mixed>  $facts
     */
    public function __construct(
        public bool $matched,
        public string $reasonCode,
        public array $facts = [],
    ) {}
}
