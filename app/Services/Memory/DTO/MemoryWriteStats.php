<?php

namespace App\Services\Memory\DTO;

final readonly class MemoryWriteStats
{
    public function __construct(
        public int $created = 0,
        public int $reinforced = 0,
        public int $superseded = 0,
        public int $disputed = 0,
        public int $topics = 0,
        public int $ignored = 0,
    ) {}

    public function withCreated(): self
    {
        return new self($this->created + 1, $this->reinforced, $this->superseded, $this->disputed, $this->topics, $this->ignored);
    }

    public function withReinforced(): self
    {
        return new self($this->created, $this->reinforced + 1, $this->superseded, $this->disputed, $this->topics, $this->ignored);
    }

    public function withSuperseded(): self
    {
        return new self($this->created, $this->reinforced, $this->superseded + 1, $this->disputed, $this->topics, $this->ignored);
    }

    public function withDisputed(): self
    {
        return new self($this->created, $this->reinforced, $this->superseded, $this->disputed + 1, $this->topics, $this->ignored);
    }

    public function withTopic(): self
    {
        return new self($this->created, $this->reinforced, $this->superseded, $this->disputed, $this->topics + 1, $this->ignored);
    }

    public function withIgnored(): self
    {
        return new self($this->created, $this->reinforced, $this->superseded, $this->disputed, $this->topics, $this->ignored + 1);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'reinforced' => $this->reinforced,
            'superseded' => $this->superseded,
            'disputed' => $this->disputed,
            'topics' => $this->topics,
            'ignored' => $this->ignored,
        ];
    }
}
