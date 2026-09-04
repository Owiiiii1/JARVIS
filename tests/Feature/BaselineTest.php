<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

class BaselineTest extends TestCase
{
    public function test_guest_can_view_the_login_page(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_guest_cannot_access_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect();
    }

    public function test_owner_can_access_the_dashboard(): void
    {
        $this->actingAs($this->existingOwner())
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_owner_can_access_settings(): void
    {
        $this->actingAs($this->existingOwner())
            ->get('/settings')
            ->assertOk();
    }

    public function test_legacy_telegram_tab_opens_integrations(): void
    {
        $this->actingAs($this->existingOwner())
            ->get('/settings?tab=telegram')
            ->assertOk();
    }

    public function test_legacy_crm_urls_are_not_found(): void
    {
        foreach (['/customers', '/services', '/staff', '/orders'] as $url) {
            $this->get($url)->assertNotFound();
            $this->actingAs($this->existingOwner())->get($url)->assertNotFound();
        }
    }

    private function existingOwner(): User
    {
        $user = User::query()->where('role', UserRole::Owner)->first();

        $this->assertNotNull($user, 'Baseline auth tests require an existing owner user.');

        return $user;
    }
}
