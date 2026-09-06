<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Models\ChannelIdentity;
use App\Models\Reminder;
use App\Services\Telegram\TelegramBotManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReminderDeliveryService
{
    public const MAX_ATTEMPTS = ReminderDeliveryState::MAX_ATTEMPTS;

    public const NO_CHANNEL_RECHECK_MINUTES = ReminderDeliveryState::NO_CHANNEL_RECHECK_MINUTES;

    public function __construct(
        private readonly TelegramBotManager $telegram,
    ) {}

    public function deliver(Reminder $reminder): void
    {
        $reminder->loadMissing('user');
        $user = $reminder->user;

        if ($user === null || ! $user->isActive()) {
            $this->cancel($reminder, 'user_disabled');

            return;
        }

        $identity = ChannelIdentity::findTelegramForUser((int) $user->id);

        if ($identity === null || ! filled($identity->external_chat_id)) {
            ReminderDeliveryState::deferNoChannel($reminder, CarbonImmutable::now('UTC'));
            $reminder->save();

            Log::info('reminder waiting for delivery channel', [
                'reminder_id' => $reminder->id,
                'user_id' => $user->id,
                'status' => ReminderStatus::Scheduled->value,
                'delivery_state' => ReminderDeliveryState::STATE_NO_CHANNEL,
            ]);

            return;
        }

        $body = '⏰ Напоминание: '.$reminder->text;

        try {
            $this->telegram->sendTextMessage((string) $identity->external_chat_id, $body);
        } catch (Throwable $exception) {
            Log::warning('reminder delivery failed', [
                'reminder_id' => $reminder->id,
                'user_id' => $user->id,
                'error_class' => $exception::class,
            ]);

            $this->failOrRetry($reminder, 'telegram_delivery_failed');

            return;
        }

        ReminderDeliveryState::markDelivered($reminder, CarbonImmutable::now('UTC'));
        $reminder->save();

        Log::info('reminder delivered', [
            'reminder_id' => $reminder->id,
            'user_id' => $user->id,
            'status' => ReminderStatus::Delivered->value,
        ]);
    }

    private function cancel(Reminder $reminder, string $reason): void
    {
        $metadata = $reminder->metadata ?? [];
        $metadata['reason'] = $reason;

        $reminder->forceFill([
            'status' => ReminderStatus::Cancelled,
            'cancelled_at' => now(),
            'last_error' => $reason,
            'metadata' => $metadata,
        ])->save();

        Log::info('reminder cancelled', [
            'reminder_id' => $reminder->id,
            'user_id' => $reminder->user_id,
            'status' => ReminderStatus::Cancelled->value,
            'error_class' => $reason,
        ]);
    }

    private function failOrRetry(Reminder $reminder, string $error): void
    {
        ReminderDeliveryState::retryOrFail($reminder, $error, CarbonImmutable::now('UTC'));
        $reminder->save();

        $attempts = (int) (($reminder->metadata['attempts'] ?? 0));

        if ($reminder->status === ReminderStatus::Scheduled) {
            Log::info('reminder retry scheduled', [
                'reminder_id' => $reminder->id,
                'user_id' => $reminder->user_id,
                'status' => ReminderStatus::Scheduled->value,
                'error_class' => $error,
                'attempts' => $attempts,
            ]);

            return;
        }

        Log::warning('reminder failed', [
            'reminder_id' => $reminder->id,
            'user_id' => $reminder->user_id,
            'status' => ReminderStatus::Failed->value,
            'error_class' => $error,
            'attempts' => $attempts,
        ]);
    }
}
