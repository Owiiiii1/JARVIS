<?php

namespace Tests\Unit;

use App\Services\Ai\AiFailureFallback;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Ai\Exceptions\AiEmptyResponseException;
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
            new AiEmptyResponseException,
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

    public function test_empty_response_asks_user_to_try_again(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiEmptyResponseException,
            [],
        );

        $this->assertSame(AiFailureFallback::ANSWER_UNAVAILABLE, $fallback);
    }

    public function test_technical_provider_failure_is_not_hidden(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiProviderException('upstream unavailable'),
            [],
        );

        $this->assertNull($fallback);
    }

    public function test_completed_tool_reports_follow_up_technical_failure(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiProviderException('upstream unavailable'),
            [
                ToolResult::success('call-1', UpdateAssistantProfileTool::NAME, [
                    'success' => true,
                ]),
            ],
        );

        $this->assertSame(
            'Готово, настройки ассистента сохранены. Но при формировании ответа произошла техническая ошибка.',
            $fallback,
        );
    }
}
