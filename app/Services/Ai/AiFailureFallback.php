<?php

namespace App\Services\Ai;

use App\Services\Ai\DTO\ToolResult;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Exceptions\AiSafetyException;
use App\Services\Tools\CompleteAssistantOnboardingTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\GetAssistantProfileTool;
use App\Services\Tools\SetTelegramResponseModeTool;
use App\Services\Tools\UpdateAssistantProfileTool;
use Throwable;

final class AiFailureFallback
{
    public const ANSWER_UNAVAILABLE = 'Сейчас ответ на этот вопрос недоступен. Пожалуйста, спросите ещё раз.';

    public const SAFETY_RESPONSE = 'В этой ситуации важно выбрать безопасный вариант. Не предпринимай опасных действий и не оставайся с риском один на один: обратись к доверенному человеку или специалисту, а при непосредственной угрозе — в экстренную службу. Я могу помочь продумать безопасные следующие шаги.';

    public const ONLINE_MEETING_RESPONSE = 'Не соглашайся встречаться с интернет-знакомым наедине, особенно если его возраст вызывает сомнения. Не сообщай адрес, школу, телефон и другие личные данные. Покажи переписку родителю или другому доверенному взрослому и принимай решение только вместе с ним. Если человек давит, просит сохранить встречу в секрете или прислать личные фотографии — прекрати общение и заблокируй его.';

    /**
     * @param  list<ToolResult>  $results
     */
    public function resolve(Throwable $exception, array $results, ?string $userText = null): ?string
    {
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

        if ($exception instanceof AiSafetyException) {
            return $this->safetyResponse($userText);
        }

        if ($exception instanceof AiProviderException || $exception instanceof AiConfigurationException) {
            return self::ANSWER_UNAVAILABLE;
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

    private function safetyResponse(?string $userText): string
    {
        $text = mb_strtolower(trim((string) $userText));
        $meeting = preg_match('/встр(?:ет|ич)|meet(?:ing)?/u', $text) === 1;
        $onlineContact = preg_match('/интернет|онлайн|weplay|telegram|чат|фото|photo|online/u', $text) === 1;
        $minor = preg_match('/\b(?:1[0-7]|[5-9])\s*(?:лет|год|years?|yo)\b/u', $text) === 1;

        if ($meeting && ($onlineContact || $minor)) {
            return self::ONLINE_MEETING_RESPONSE;
        }

        return self::SAFETY_RESPONSE;
    }
}
