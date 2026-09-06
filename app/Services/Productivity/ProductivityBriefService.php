<?php

namespace App\Services\Productivity;

use App\Enums\ProductivityBriefMode;
use App\Models\User;
use Carbon\CarbonImmutable;

final class ProductivityBriefService
{
    public function __construct(
        private readonly ProductivityBriefCollector $collector = new ProductivityBriefCollector,
        private readonly ProductivityBriefRenderer $renderer = new ProductivityBriefRenderer,
        private readonly ?SynthesizesProductivityBrief $synthesizer = null,
    ) {}

    /**
     * @param  list<mixed>  $tasks
     * @param  list<mixed>  $reminders
     * @param  list<array{title: string, start?: ?string}>  $calendar
     * @param  list<mixed>  $projects
     * @param  list<mixed>  $notifications
     * @return array{text: string, sources: ProductivityBriefSources, ai_used: bool}
     */
    public function compose(
        User $user,
        ProductivityBriefMode $mode,
        CarbonImmutable $now,
        array $tasks,
        array $reminders,
        array $calendar = [],
        array $projects = [],
        array $notifications = [],
    ): array {
        $sources = $this->collector->collect($user, $mode, $now, $tasks, $reminders, $calendar, $projects, $notifications);
        $deterministic = $this->renderer->deterministic($sources);
        $aiUsed = false;
        $text = $deterministic;

        if ($this->synthesizer !== null) {
            $phrased = $this->synthesizer->synthesize($user, $mode->value, $deterministic, $sources->toArray());

            if (is_string($phrased) && trim($phrased) !== '') {
                $text = trim($phrased);
                $aiUsed = true;
            }
        }

        return [
            'text' => $text,
            'sources' => $sources,
            'ai_used' => $aiUsed,
        ];
    }
}
