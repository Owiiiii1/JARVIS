<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Watchers\Contracts\GmailWatcherClient;

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

        return $this->messages;
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
