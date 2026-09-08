<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Watchers\Contracts\GmailWatcherClient;
use App\Services\Watchers\GmailWatcherQuery;

final class FakeGmailWatcherClient implements GmailWatcherClient
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public int $searchCalls = 0;

    public ?\Throwable $exception = null;

    public function search(User $user, array $source): array
    {
        $this->searchCalls++;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        $rows = [];
        foreach ($this->messages as $message) {
            $sender = (string) ($message['sender'] ?? ($message['from'] ?? ''));
            $subject = (string) ($message['subject'] ?? '');
            if (! GmailWatcherQuery::messageMatches($source, $sender, $subject)) {
                continue;
            }
            $rows[] = $message;
        }

        return $rows;
    }

    public function snippet(User $user, array $source, string $messageId): array
    {
        foreach ($this->messages as $message) {
            if ((string) ($message['id'] ?? '') === $messageId) {
                return [
                    'id' => $messageId,
                    'subject' => (string) ($message['subject'] ?? ''),
                    'snippet' => (string) ($message['snippet'] ?? 'Please review the Staff App.'),
                    'sender' => (string) ($message['sender'] ?? ''),
                ];
            }
        }

        return ['id' => $messageId, 'subject' => '', 'snippet' => '', 'sender' => ''];
    }
}
