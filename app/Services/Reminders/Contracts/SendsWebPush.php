<?php

namespace App\Services\Reminders\Contracts;

use App\Models\PushSubscription;
use App\Services\Reminders\WebPushSendResult;

interface SendsWebPush
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(PushSubscription $subscription, array $payload): WebPushSendResult;
}
