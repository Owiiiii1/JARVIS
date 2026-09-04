<?php

namespace App\Services\Groups\DTO;

use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class GroupSearchRequest
{
    /**
     * @param  list<string>  $types
     */
    public function __construct(
        public User $user,
        public string $query,
        public ?string $groupHint = null,
        public ?string $projectHint = null,
        public ?string $range = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public array $types = [],
        public bool $includeRawIfNeeded = false,
        public ?int $limit = null,
        public ?CarbonImmutable $now = null,
    ) {}
}
