<?php

namespace App\Services\Watchers\Contracts;

use App\Models\User;

interface GmailWatcherClient
{
    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    public function search(User $user, array $source): array;

    /**
     * @return array<string, mixed>
     */
    public function snippet(User $user, array $source, string $messageId): array;
}
