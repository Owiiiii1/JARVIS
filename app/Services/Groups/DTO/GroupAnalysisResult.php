<?php

namespace App\Services\Groups\DTO;

final readonly class GroupAnalysisResult
{
    /**
     * @param  list<GroupDecisionCandidate>  $decisions
     * @param  list<GroupTaskCandidate>  $tasks
     * @param  list<GroupEventCandidate>  $events
     */
    public function __construct(
        public ?GroupSummaryCandidate $summary,
        public array $decisions,
        public array $tasks,
        public array $events,
    ) {}

    public function isEmpty(): bool
    {
        return $this->summary === null
            && $this->decisions === []
            && $this->tasks === []
            && $this->events === [];
    }
}
