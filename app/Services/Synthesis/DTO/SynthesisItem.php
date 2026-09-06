<?php

namespace App\Services\Synthesis\DTO;

final readonly class SynthesisItem
{
    /**
     * @param  list<SourceRef>  $sources
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $kind,
        public string $title,
        public ?string $why = null,
        public ?string $since = null,
        public ?string $dueAt = null,
        public int $score = 0,
        public array $sources = [],
        public array $extra = [],
        public ?string $recommendedNextStep = null,
        public string $fingerprint = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $refs = [];

        foreach ($this->sources as $source) {
            $refs[] = $source->toArray();
        }

        return array_filter([
            'kind' => $this->kind,
            'title' => $this->title,
            'why' => $this->why,
            'since' => $this->since,
            'due_at' => $this->dueAt,
            'score' => $this->score,
            'sources' => $refs,
            'recommended_next_step' => $this->recommendedNextStep,
            ...$this->extra,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }
}
