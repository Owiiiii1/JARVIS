<?php

namespace Tests\Unit;

use App\Enums\AiRoleKey;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Ai\AiConfigurationResolver;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class AiConfigurationResolverTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_owner_resolves_to_owner_conversation(): void
    {
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($owner);

        $resolved = app(AiConfigurationResolver::class)->resolveConversation($owner);

        $this->assertSame(AiRoleKey::OwnerConversation, $resolved->roleKey());
        $this->assertNotSame(AiRoleKey::UserConversation, $resolved->roleKey());
        $this->assertNotSame(AiRoleKey::OwnerAnalysis, $resolved->roleKey());
    }

    public function test_user_resolves_to_user_conversation(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $resolved = app(AiConfigurationResolver::class)->resolveConversation($user);

            $this->assertSame(AiRoleKey::UserConversation, $resolved->roleKey());
            $this->assertNotSame(AiRoleKey::OwnerConversation, $resolved->roleKey());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_analysis_resolves_separately(): void
    {
        $resolved = app(AiConfigurationResolver::class)->resolveAnalysis();

        $this->assertSame(AiRoleKey::OwnerAnalysis, $resolved->roleKey());
    }
}
