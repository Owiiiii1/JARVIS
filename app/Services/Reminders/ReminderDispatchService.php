<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use Illuminate\Support\Facades\DB;

final class ReminderDispatchService
{
    public function __construct(
        private readonly ReminderDeliveryService $delivery,
    ) {}

    public function dispatchDue(int $limit = 25): int
    {
        $claimed = $this->claimDue($limit);

        foreach ($claimed as $reminder) {
            $this->delivery->deliver($reminder);
        }

        return count($claimed);
    }

    /**
     * @return list<Reminder>
     */
    public function claimDue(int $limit = 25): array
    {
        return DB::transaction(function () use ($limit): array {
            $query = Reminder::query()
                ->where('status', ReminderStatus::Scheduled)
                ->where('run_at', '<=', now())
                ->where(function ($builder): void {
                    $builder
                        ->whereNull('metadata->next_retry_at')
                        ->orWhere('metadata->next_retry_at', '<=', now()->utc()->toDateTimeString());
                })
                ->orderBy('run_at')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate();

            if (method_exists($query, 'skipLocked')) {
                $query->skipLocked();
            }

            $reminders = $query->get();

            foreach ($reminders as $reminder) {
                $reminder->forceFill([
                    'status' => ReminderStatus::Processing,
                ])->save();
            }

            return $reminders->all();
        });
    }
}
