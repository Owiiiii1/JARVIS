<?php

namespace App\Services\Tools;

final class CalendarEventIdempotency
{
    public static function googleEventId(int $userId, int $conversationId, string $toolCallId): string
    {
        $hash = hash('sha256', $userId.'|'.$conversationId.'|'.$toolCallId);

        return 'jvs'.substr($hash, 0, 40);
    }
}
