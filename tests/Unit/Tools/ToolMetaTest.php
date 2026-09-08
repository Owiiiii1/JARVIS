<?php

namespace Tests\Unit\Tools;

use App\Enums\ToolMutationKind;
use App\Enums\ToolOperationClass;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;
use PHPUnit\Framework\TestCase;

class ToolMetaTest extends TestCase
{
    public function test_read_only_storage_is_not_a_mutation(): void
    {
        $meta = new ToolMeta(UserCapability::STORAGE, ToolOperationClass::Read);

        $this->assertTrue($meta->isReadOnly());
        $this->assertFalse($meta->isMutation());
        $this->assertSame(ToolMutationKind::Read, $meta->mutationKind());
    }

    public function test_core_write_without_provider_is_internal(): void
    {
        $meta = new ToolMeta(UserCapability::REMINDERS, ToolOperationClass::Write);

        $this->assertTrue($meta->isMutation());
        $this->assertSame(ToolMutationKind::WriteInternal, $meta->mutationKind());
    }

    public function test_gmail_write_is_external(): void
    {
        $meta = new ToolMeta(UserCapability::GMAIL, ToolOperationClass::Write, provider: 'google');

        $this->assertTrue($meta->isMutation());
        $this->assertSame(ToolMutationKind::WriteExternal, $meta->mutationKind());
    }

    public function test_destructive_storage_stays_destructive(): void
    {
        $meta = new ToolMeta(UserCapability::STORAGE, ToolOperationClass::Destructive);

        $this->assertTrue($meta->isMutation());
        $this->assertSame(ToolMutationKind::Destructive, $meta->mutationKind());
    }
}
