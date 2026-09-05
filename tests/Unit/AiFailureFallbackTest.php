<?php

namespace Tests\Unit;

use App\Services\Ai\AiFailureFallback;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Exceptions\AiSafetyException;
use App\Services\Tools\CompleteAssistantOnboardingTool;
use App\Services\Tools\UpdateAssistantProfileTool;
use PHPUnit\Framework\TestCase;

class AiFailureFallbackTest extends TestCase
{
    public function test_safety_block_gets_safe_user_response_without_tools(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiSafetyException('SAFETY'),
            [],
        );

        $this->assertSame(AiFailureFallback::SAFETY_RESPONSE, $fallback);
        $this->assertStringNotContainsString('ошибка ИИ', mb_strtolower((string) $fallback));
    }

    public function test_online_meeting_safety_block_gets_practical_guidance(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiSafetyException('SAFETY'),
            [],
            'Мне 11 лет, интернет-знакомый по фото выглядит взрослым и зовёт встретиться.',
        );

        $this->assertSame(AiFailureFallback::ONLINE_MEETING_RESPONSE, $fallback);
        $this->assertStringContainsString('доверенному взрослому', (string) $fallback);
        $this->assertStringNotContainsString('не могу ответить', mb_strtolower((string) $fallback));
    }

    public function test_successful_onboarding_tool_gets_completion_fallback(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiProviderException('empty response'),
            [
                ToolResult::success('call-1', UpdateAssistantProfileTool::NAME, [
                    'success' => true,
                ]),
                ToolResult::success('call-2', CompleteAssistantOnboardingTool::NAME, [
                    'success' => true,
                ]),
            ],
        );

        $this->assertSame(
            'Готово, знакомство завершено. Я запомнил настройки и информацию о тебе.',
            $fallback,
        );
    }

    public function test_plain_provider_failure_without_completed_tools_has_no_false_success(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiProviderException('upstream unavailable'),
            [],
        );

        $this->assertSame(AiFailureFallback::ANSWER_UNAVAILABLE, $fallback);
    }
}
