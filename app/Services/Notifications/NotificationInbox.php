<?php

namespace App\Services\Notifications;

use App\Enums\JarvisNotificationSeverity;
use App\Enums\JarvisNotificationType;
use App\Models\JarvisNotification;
use App\Models\User;
use Carbon\CarbonImmutable;

final class NotificationInbox
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function make(
        User $user,
        JarvisNotificationType $type,
        string $title,
        string $body,
        string $dedupeKey,
        CarbonImmutable $occurredAt,
        JarvisNotificationSeverity $severity = JarvisNotificationSeverity::Info,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $actionUrl = null,
        array $metadata = [],
        bool $aiPhrased = false,
    ): JarvisNotification {
        $notification = new JarvisNotification;
        $notification->forceFill([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'severity' => $severity,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'dedupe_key' => $dedupeKey,
            'action_url' => $actionUrl,
            'occurred_at' => $occurredAt->utc(),
            'ai_phrased' => $aiPhrased,
            'metadata' => $metadata,
        ]);

        return $notification;
    }

    public function shouldCreate(?JarvisNotification $existing): bool
    {
        return $existing === null;
    }

    public function markRead(JarvisNotification $notification, CarbonImmutable $now): void
    {
        if ($notification->read_at !== null) {
            return;
        }

        $notification->forceFill(['read_at' => $now->utc()]);
    }

    public function dismiss(JarvisNotification $notification, CarbonImmutable $now): void
    {
        $notification->forceFill([
            'dismissed_at' => $now->utc(),
            'read_at' => $notification->read_at ?? $now->utc(),
        ]);
    }

    /**
     * @param  list<JarvisNotification>  $notifications
     */
    public function unreadCount(array $notifications): int
    {
        $count = 0;

        foreach ($notifications as $notification) {
            if ($notification->isUnread()) {
                $count++;
            }
        }

        return $count;
    }
}
