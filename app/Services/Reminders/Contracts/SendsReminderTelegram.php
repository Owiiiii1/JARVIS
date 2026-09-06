<?php

namespace App\Services\Reminders\Contracts;

interface SendsReminderTelegram
{
    public function send(string $chatId, string $text): void;
}
