<?php

namespace App\Services\Synthesis;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class SynthesisCache
{
    public function remember(User $user, string $parts, callable $callback): mixed
    {
        $ttl = max(15, (int) config('synthesis.cache_ttl_seconds', 90));

        return Cache::remember($this->key($user, $parts), $ttl, $callback);
    }

    public function bump(User $user): void
    {
        $this->bumpUserId((int) $user->id);
    }

    public function bumpUserId(int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        $versionKey = $this->versionKey($userId);
        $current = (int) Cache::get($versionKey, 1);
        Cache::forever($versionKey, $current + 1);
    }

    public function version(User $user): int
    {
        return max(1, (int) Cache::get($this->versionKey((int) $user->id), 1));
    }

    private function key(User $user, string $parts): string
    {
        return 'synthesis:u'.(int) $user->id.':v'.$this->version($user).':'.sha1($parts);
    }

    private function versionKey(int $userId): string
    {
        return 'synthesis:ver:'.$userId;
    }
}
