<?php

namespace Tests\Feature;

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

    public function test_authenticated_user_can_access_the_dashboard(): void
    {
        $this->actingAs($this->existingAdmin())
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_authenticated_user_can_access_settings(): void
    {
        $this->actingAs($this->existingAdmin())
            ->get('/settings')
            ->assertOk();
    }

    public function test_authenticated_user_can_access_telegram_settings(): void
    {
        $this->actingAs($this->existingAdmin())
            ->get('/settings?tab=telegram')
            ->assertOk();
    }

    public function test_legacy_crm_urls_are_not_found(): void
    {
        foreach (['/customers', '/services', '/staff', '/orders'] as $url) {
            $this->get($url)->assertNotFound();
            $this->actingAs($this->existingAdmin())->get($url)->assertNotFound();
        }
    }

    private function existingAdmin(): User
    {
        $user = User::query()->first();

        $this->assertNotNull($user, 'Baseline auth tests require an existing admin user.');

        return $user;
    }
}
