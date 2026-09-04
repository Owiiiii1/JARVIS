<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;

final readonly class ToolMeta
{
    public function __construct(
        public string $capability,
        public ToolOperationClass $operation,
        public ?string $provider = null,
        public ?string $confirmationHint = null,
        public bool $alwaysConfirm = false,
    ) {}
}
