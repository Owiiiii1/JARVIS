<?php

namespace Tests\Unit;

use Tests\TestCase;

class WorkspaceConfirmationLifecycleTest extends TestCase
{
    public function test_voice_overlay_selector_requires_pending_status(): void
    {
        $workspace = (string) file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));
        $helper = (string) file_get_contents(base_path('resources/js/personal-workspace/confirmationState.js'));

        $this->assertStringContainsString('isActionableConfirmation(item?.pending_confirmation)', $workspace);
        $this->assertStringNotContainsString('.find((item) => item?.pending_confirmation?.id)?.pending_confirmation', $workspace);
        $this->assertStringContainsString("return confirmationLifecycleStatus(pending) === 'pending'", $helper);
        $this->assertStringContainsString('already_resolved', $workspace);
        $this->assertStringContainsString('withConfirmationState', $workspace);
        $this->assertStringContainsString('Действие отменено', $helper);
        $this->assertStringContainsString('Истекло', $helper);
        $this->assertStringContainsString('Письмо отправлено', $helper);
    }
}
