<?php

namespace App\Services\Synthesis\DTO;

use App\Enums\SynthesisType;
use Carbon\CarbonImmutable;

final class SynthesisResult
{
    /**
     * @param  list<SynthesisItem>  $blockers
     * @param  list<SynthesisItem>  $waitingFor
     * @param  list<SynthesisItem>  $commitments
     * @param  list<SynthesisItem>  $openWork
     * @param  list<SynthesisItem>  $recentChanges
     * @param  list<SynthesisItem>  $people
     * @param  list<SynthesisItem>  $upcoming
     * @param  list<SynthesisItem>  $attention
     * @param  list<SynthesisItem>  $openLoops
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<array<string, mixed>>  $sources
     * @param  array<string, mixed>  $project
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $freshness
     */
    public function __construct(
        public SynthesisType $type,
        public CarbonImmutable $generatedAt,
        public string $timezone,
        public ?string $summary = null,
        public bool $narrativeUsed = false,
        public array $blockers = [],
        public array $waitingFor = [],
        public array $commitments = [],
        public array $openWork = [],
        public array $recentChanges = [],
        public array $people = [],
        public array $upcoming = [],
        public array $attention = [],
        public array $openLoops = [],
        public array $conflicts = [],
        public array $sources = [],
        public array $project = [],
        public array $person = [],
        public array $freshness = [],
        public array $facts = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'generated_at' => $this->generatedAt->toIso8601String(),
            'timezone' => $this->timezone,
            'summary' => $this->summary,
            'narrative_used' => $this->narrativeUsed,
            'project' => $this->project !== [] ? $this->project : null,
            'person' => $this->person !== [] ? $this->person : null,
            'blockers' => $this->mapItems($this->blockers),
            'waiting_for' => $this->mapItems($this->waitingFor),
            'commitments' => $this->mapItems($this->commitments),
            'open_work' => $this->mapItems($this->openWork),
            'recent_changes' => $this->mapItems($this->recentChanges),
            'people' => $this->mapItems($this->people),
            'upcoming' => $this->mapItems($this->upcoming),
            'attention' => $this->mapItems($this->attention),
            'open_loops' => $this->mapItems($this->openLoops),
            'conflicts' => $this->conflicts,
            'freshness' => $this->freshness,
            'sources' => $this->sources,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  list<SynthesisItem>  $items
     * @return list<array<string, mixed>>
     */
    private function mapItems(array $items): array
    {
        return array_map(static fn (SynthesisItem $item): array => $item->toArray(), $items);
    }
}
