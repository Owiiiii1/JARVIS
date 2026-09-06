<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Reminders\PushPayloadBuilder;

final class NotificationUrlPolicy
{
    public function __construct(
        private readonly PushPayloadBuilder $payloads = new PushPayloadBuilder,
    ) {}

    public function workspacePath(User $user, ?string $query = null, ?int $conversationId = null): string
    {
        $prefix = $user->isOwner() ? '/jarvis' : '/chat';

        if ($conversationId !== null && $conversationId > 0) {
            $path = $prefix.'/chats/'.$conversationId;
        } else {
            $path = $prefix;
        }

        if ($query !== null && $query !== '') {
            $path .= (str_contains($path, '?') ? '&' : '?').ltrim($query, '?');
        }

        return $path;
    }

    public function isSafe(?string $url): bool
    {
        if ($url === null || $url === '') {
            return true;
        }

        return $this->payloads->isAllowlisted($url);
    }

    public function sanitize(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return $this->isSafe($url) ? $url : null;
    }
}
