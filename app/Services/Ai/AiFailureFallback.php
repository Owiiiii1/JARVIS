<?php

namespace App\Services\Ai;

use App\Services\Ai\DTO\ToolResult;
use App\Services\Ai\Exceptions\AiSafetyException;
use App\Services\Tools\CompleteAssistantOnboardingTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\GetAssistantProfileTool;
use App\Services\Tools\SetTelegramResponseModeTool;
use App\Services\Tools\UpdateAssistantProfileTool;
use Throwable;

final class AiFailureFallback
{
    public const SAFETY_RESPONSE = 'Я не могу ответить на это сообщение в обычном режиме из-за ограничений безопасности. Если ситуация может быть опасной, не действуй в одиночку: обратись к родителю, другому доверенному взрослому или в экстренной ситуации позвони 112. Можешь задать вопрос короче — я постараюсь помочь безопасно.';

    /**
     * @param  list<ToolResult>  $results
     */
    public function resolve(Throwable $exception, array $results): ?string
    {
        if ($exception instanceof AiSafetyException) {
            return self::SAFETY_RESPONSE;
        }

        foreach (array_reverse($results) as $result) {
            if ($result->name !== CreateReminderTool::NAME) {
                continue;
            }

            if ($result->success) {
                $text = trim((string) ($result->payload['text'] ?? ''));

                return $text === ''
                    ? 'Хорошо, напоминание создано.'
                    : 'Хорошо, напомню: '.$text.'.';
            }

            if (($result->payload['error'] ?? null) === 'telegram_not_connected') {
                return 'Для получения напоминаний сначала подключите Telegram.';
            }
        }

        foreach (array_reverse($results) as $result) {
            if (! $result->success) {
                continue;
            }

            return match ($result->name) {
                CompleteAssistantOnboardingTool::NAME => 'Готово, знакомство завершено. Я запомнил настройки и информацию о тебе.',
                UpdateAssistantProfileTool::NAME => 'Готово, настройки ассистента сохранены.',
                GetAssistantProfileTool::NAME => 'Профиль ассистента загружен.',
                SetTelegramResponseModeTool::NAME => $this->telegramModeFallback($result),
                default => 'Готово.',
            };
        }

        return null;
    }

    private function telegramModeFallback(ToolResult $result): string
    {
        return match ((string) ($result->payload['mode'] ?? '')) {
            'voice' => 'Готово. В Telegram буду отвечать голосом, когда это возможно.',
            'auto' => 'Готово. В Telegram включён автоматический режим ответа.',
            'text' => 'Готово. В Telegram буду отвечать текстом.',
            default => 'Готово. Режим ответа в Telegram обновлён.',
        };
    }
}
