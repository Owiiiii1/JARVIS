<?php

namespace App\Services\Tools;

use App\Enums\ToolMutationKind;
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

    public function mutationKind(): ToolMutationKind
    {
        if ($this->operation === ToolOperationClass::Read) {
            return ToolMutationKind::Read;
        }

        if ($this->operation === ToolOperationClass::Destructive) {
            return ToolMutationKind::Destructive;
        }

        if ($this->provider !== null) {
            return ToolMutationKind::WriteExternal;
        }

        return ToolMutationKind::WriteInternal;
    }

    public function isReadOnly(): bool
    {
        return $this->mutationKind()->isReadOnly();
    }

    public function isMutation(): bool
    {
        return $this->mutationKind()->isMutation();
    }
}
