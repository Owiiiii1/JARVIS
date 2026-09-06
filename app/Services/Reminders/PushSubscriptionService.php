<?php

namespace App\Services\Reminders;

use App\Models\PushSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class PushSubscriptionService
{
    /**
     * @return Collection<int, PushSubscription>
     */
    public function activeFor(User $user): Collection
    {
        return PushSubscription::query()
            ->where('user_id', $user->id)
            ->active()
            ->orderBy('id')
            ->get();
    }

    public function hasActive(User $user): bool
    {
        return PushSubscription::query()
            ->where('user_id', $user->id)
            ->active()
            ->exists();
    }

    public function subscribe(
        User $user,
        string $endpoint,
        string $p256dh,
        string $auth,
        ?string $userAgent = null,
    ): PushSubscription {
        if (! $user->isActive()) {
            throw new ReminderException('user_inactive', 'User is not active.');
        }

        $endpoint = trim($endpoint);
        $p256dh = trim($p256dh);
        $auth = trim($auth);

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            throw new ReminderException('invalid_subscription', 'Push subscription is incomplete.');
        }

        if (strlen($endpoint) > 512) {
            throw new ReminderException('invalid_subscription', 'Push endpoint is too long.');
        }

        $existing = PushSubscription::query()->where('endpoint', $endpoint)->first();

        if ($existing !== null && (int) $existing->user_id !== (int) $user->id) {
            throw new ReminderException('not_found', 'Push subscription not found.');
        }

        $subscription = $existing ?? new PushSubscription;
        $subscription->forceFill([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth' => $auth,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 512) : null,
            'is_active' => true,
            'revoked_at' => null,
            'last_used_at' => CarbonImmutable::now('UTC'),
        ]);
        $subscription->save();

        return $subscription;
    }

    public function unsubscribe(User $user, string $endpoint): void
    {
        $subscription = PushSubscription::query()
            ->where('user_id', $user->id)
            ->where('endpoint', $endpoint)
            ->first();

        if ($subscription === null) {
            throw new ReminderException('not_found', 'Push subscription not found.');
        }

        $this->revoke($subscription);
    }

    public function revoke(PushSubscription $subscription): void
    {
        $subscription->forceFill([
            'is_active' => false,
            'revoked_at' => CarbonImmutable::now('UTC'),
        ]);

        if ($subscription->exists) {
            $subscription->save();
        }

        try {
            Log::info('push subscription revoked', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
            ]);
        } catch (\Throwable) {
        }
    }

    public function assertOwned(User $user, ?PushSubscription $subscription): PushSubscription
    {
        if ($subscription === null || (int) $subscription->user_id !== (int) $user->id) {
            throw new ReminderException('not_found', 'Push subscription not found.');
        }

        return $subscription;
    }
}
