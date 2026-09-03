<?php

namespace App\Services\Conversations;

use App\Models\Message;

final readonly class PersistMessageResult
{
    public function __construct(
        public Message $message,
        public bool $created,
    ) {}
}
