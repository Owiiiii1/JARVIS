<?php

namespace App\Services\Reminders;

use App\Enums\WebPushSendOutcome;
use App\Models\PushSubscription;
use App\Services\Reminders\Contracts\SendsWebPush;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

final class MinishlinkWebPushSender implements SendsWebPush
{
    public function send(PushSubscription $subscription, array $payload): WebPushSendResult
    {
        if (! VapidConfig::isConfigured()) {
            return new WebPushSendResult(WebPushSendOutcome::Skipped, 'vapid_not_configured');
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => VapidConfig::subject(),
                    'publicKey' => VapidConfig::publicKey(),
                    'privateKey' => VapidConfig::privateKey(),
                ],
            ]);

            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->p256dh,
                    'authToken' => $subscription->auth,
                    'contentEncoding' => 'aes128gcm',
                ]),
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ['TTL' => (int) config('reminders.push.ttl_seconds', 300)],
            );
        } catch (Throwable) {
            return new WebPushSendResult(WebPushSendOutcome::Transient, 'web_push_send_failed');
        }

        if ($report->isSuccess()) {
            return new WebPushSendResult(WebPushSendOutcome::Sent);
        }

        if ($report->isSubscriptionExpired()) {
            return new WebPushSendResult(
                WebPushSendOutcome::Gone,
                'subscription_expired',
                $report->getResponse()?->getStatusCode(),
            );
        }

        $status = $report->getResponse()?->getStatusCode();

        if ($status !== null && $status >= 500) {
            return new WebPushSendResult(WebPushSendOutcome::Transient, 'web_push_upstream', $status);
        }

        if ($status === 429) {
            return new WebPushSendResult(WebPushSendOutcome::Transient, 'web_push_rate_limited', $status);
        }

        return new WebPushSendResult(WebPushSendOutcome::Permanent, 'web_push_rejected', $status);
    }
}
