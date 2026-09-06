<?php

namespace App\Services\Productivity;

use App\Models\UserProductivitySetting;
use Carbon\CarbonImmutable;

final class ProactivePolicy
{
    public function maxPerDay(): int
    {
        return max(1, (int) config('productivity.proactive.max_per_day', 3));
    }

    public function cooldownHours(): int
    {
        return max(1, (int) config('productivity.proactive.cooldown_hours', 4));
    }

    public function approachHours(): int
    {
        return max(1, (int) config('productivity.proactive.approach_hours', 2));
    }

    public function mayEmit(
        ?UserProductivitySetting $settings,
        int $todayCount,
        ?CarbonImmutable $lastSameSourceAt,
        CarbonImmutable $now,
        bool $alreadyDeduped = false,
    ): bool {
        if ($settings === null || ! $settings->proactive_enabled) {
            return false;
        }

        if ($alreadyDeduped) {
            return false;
        }

        if ($todayCount >= $this->maxPerDay()) {
            return false;
        }

        if ($lastSameSourceAt !== null && $lastSameSourceAt->utc()->addHours($this->cooldownHours())->greaterThan($now->utc())) {
            return false;
        }

        return true;
    }
}
