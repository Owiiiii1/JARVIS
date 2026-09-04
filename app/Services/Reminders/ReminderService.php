<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class ReminderService
{
    public function create(
        User $user,
        string $text,
        CarbonImmutable $runAt,
        string $timezone,
        ?Conversation $conversation = null,
        ?Message $sourceMessage = null,
    ): Reminder {
        $this->assertCanCreate($user, $text, $runAt, $timezone);

        $utc = $runAt->utc();
        $originalLocal = $runAt->setTimezone($timezone)->format('Y-m-d\TH:i:sP');

        $reminder = Reminder::query()->create([
            'user_id' => $user->id,
            'source_conversation_id' => $conversation?->id,
            'source_message_id' => $sourceMessage?->id,
            'text' => $text,
            'run_at' => $utc,
            'timezone' => $timezone,
            'original_local_time' => $originalLocal,
            'status' => ReminderStatus::Scheduled,
            'metadata' => [
                'attempts' => 0,
            ],
        ]);

        try {
            Log::info('reminder created', [
                'reminder_id' => $reminder->id,
                'user_id' => $user->id,
                'status' => ReminderStatus::Scheduled->value,
            ]);
        } catch (\Throwable) {
        }

        return $reminder;
    }

    public function localWallTimeToUtc(string $runAtLocal, string $timezone): CarbonImmutable
    {
        if (! $this->isValidTimezone($timezone)) {
            throw new ReminderException('invalid_timezone', 'Timezone is invalid.');
        }

        try {
            $parsed = CarbonImmutable::parse($runAtLocal);
        } catch (Exception $exception) {
            throw new ReminderException('invalid_time', 'run_at_local is invalid.');
        }

        $wall = $parsed->format('Y-m-d H:i:s');

        try {
            return (new CarbonImmutable($wall, new DateTimeZone($timezone)))->utc();
        } catch (Exception $exception) {
            throw new ReminderException('invalid_time', 'run_at_local is invalid.');
        }
    }

    /**
     * @return Collection<int, Reminder>
     */
    public function listUpcoming(User $user, int $limit = 8): Collection
    {
        return Reminder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [ReminderStatus::Scheduled, ReminderStatus::Processing])
            ->orderBy('run_at')
            ->limit(max(1, min(20, $limit)))
            ->get();
    }

    public function isValidTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return in_array($timezone, timezone_identifiers_list(), true);
        } catch (Exception) {
            return false;
        }
    }

    private function assertCanCreate(User $user, string $text, CarbonImmutable $runAt, string $timezone): void
    {
        if (! $user->canUseCapability(UserCapability::REMINDERS)) {
            throw new ReminderException('capability_denied', 'Reminders are not available.');
        }

        if (! $user->isActive()) {
            throw new ReminderException('user_inactive', 'User is not active.');
        }

        if (ChannelIdentity::findTelegramForUser((int) $user->id) === null) {
            throw new ReminderException('telegram_not_connected', 'Telegram is not connected.');
        }

        if (trim($text) === '') {
            throw new ReminderException('empty_text', 'Reminder text is empty.');
        }

        if (! $this->isValidTimezone($timezone)) {
            throw new ReminderException('invalid_timezone', 'Timezone is invalid.');
        }

        if ($runAt->utc()->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw new ReminderException('past_time', 'Reminder time is in the past.');
        }
    }

    public function activeCount(User $user): int
    {
        if (! $user->canUseCapability(UserCapability::REMINDERS)) {
            return 0;
        }

        return Reminder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [ReminderStatus::Scheduled, ReminderStatus::Processing])
            ->count();
    }

    /**
     * @return array{active: list<array<string, mixed>>, history: list<array<string, mixed>>, telegram_connected: bool}
     */
    public function panelFor(User $user): array
    {
        if (! $user->canUseCapability(UserCapability::REMINDERS)) {
            throw new ReminderException('capability_denied', 'Reminders are not available.');
        }

        $timezone = (string) ($user->timezone ?: 'UTC');
        $active = Reminder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [ReminderStatus::Scheduled, ReminderStatus::Processing])
            ->orderBy('run_at')
            ->limit(50)
            ->get();

        $history = Reminder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [ReminderStatus::Delivered, ReminderStatus::Cancelled, ReminderStatus::Failed])
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();

        return [
            'telegram_connected' => ChannelIdentity::findTelegramForUser((int) $user->id) !== null,
            'active_count' => $this->activeCount($user),
            'active' => $active->map(fn (Reminder $reminder): array => $this->panelRow($reminder, $timezone))->values()->all(),
            'history' => $history->map(fn (Reminder $reminder): array => $this->panelRow($reminder, $timezone))->values()->all(),
        ];
    }

    public function cancelOwned(User $user, int $reminderId): Reminder
    {
        if (! $user->canUseCapability(UserCapability::REMINDERS)) {
            throw new ReminderException('capability_denied', 'Reminders are not available.');
        }

        $reminder = Reminder::query()
            ->where('user_id', $user->id)
            ->whereKey($reminderId)
            ->first();

        if ($reminder === null) {
            throw new ReminderException('not_found', 'Reminder not found.');
        }

        if (! in_array($reminder->status, [ReminderStatus::Scheduled, ReminderStatus::Processing], true)) {
            throw new ReminderException('not_cancellable', 'This reminder cannot be cancelled.');
        }

        $reminder->forceFill([
            'status' => ReminderStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return $reminder->fresh() ?? $reminder;
    }

    /**
     * @return array<string, mixed>
     */
    private function panelRow(Reminder $reminder, string $fallbackTimezone): array
    {
        $timezone = $reminder->timezone ?: $fallbackTimezone;

        try {
            $local = $reminder->run_at?->setTimezone($timezone);
        } catch (Exception) {
            $local = $reminder->run_at?->utc();
            $timezone = 'UTC';
        }

        $recurrence = trim((string) ($reminder->recurrence_rule ?? ''));

        return [
            'id' => (int) $reminder->id,
            'text' => $reminder->text,
            'status' => $reminder->status->value,
            'run_at' => optional($reminder->run_at)?->toIso8601String(),
            'run_at_local' => $local?->format('Y-m-d\TH:i:sP'),
            'timezone' => $timezone,
            'original_local_time' => $reminder->original_local_time,
            'recurrence' => $recurrence !== '' ? $recurrence : null,
            'delivery_channel' => 'telegram',
            'cancellable' => in_array($reminder->status, [ReminderStatus::Scheduled, ReminderStatus::Processing], true),
            'created_at' => optional($reminder->created_at)?->toIso8601String(),
            'delivered_at' => optional($reminder->delivered_at)?->toIso8601String(),
            'cancelled_at' => optional($reminder->cancelled_at)?->toIso8601String(),
            'last_error' => $reminder->last_error,
        ];
    }
}
