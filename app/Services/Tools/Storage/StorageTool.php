<?php

namespace App\Services\Tools\Storage;

use App\Enums\ToolOperationClass;
use App\Services\Storage\StoredFileSearchService;
use App\Services\Storage\StoredFileService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

abstract class StorageTool implements JarvisTool
{
    public function __construct(
        protected readonly StoredFileService $files,
        protected readonly StoredFileSearchService $search,
    ) {}

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::STORAGE);
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::STORAGE,
            operation: $this->operation(),
            confirmationHint: $this->confirmationHint(),
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    protected function confirmationHint(): ?string
    {
        return null;
    }
}
