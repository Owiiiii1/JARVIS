<?php

namespace App\Services\Tools;

use App\Enums\ToolMutationKind;

final class ToolSemantics
{
    public function __construct(
        private readonly ToolRegistry $tools,
    ) {}

    public function meta(string $name): ?ToolMeta
    {
        return $this->tools->resolve($name)?->meta();
    }

    public function mutationKind(string $name): ToolMutationKind
    {
        return $this->meta($name)?->mutationKind() ?? ToolMutationKind::Read;
    }

    public function isReadOnly(string $name): bool
    {
        return $this->mutationKind($name)->isReadOnly();
    }

    public function isMutation(string $name): bool
    {
        return $this->mutationKind($name)->isMutation();
    }
}
