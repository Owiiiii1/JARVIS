<?php

namespace App\Services\Productivity;

use App\Models\User;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProductivityBriefAiSynthesizer implements SynthesizesProductivityBrief
{
    public function __construct(
        private readonly AiChatGateway $gateway,
        private readonly AiConfigurationResolver $roles,
    ) {}

    public function synthesize(User $user, string $mode, string $deterministic, array $sources): ?string
    {
        try {
            $configuration = $this->roles->resolveConversation($user);
            $payload = json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $response = $this->gateway->chat($configuration, new AiChatRequest(
                model: (string) $configuration->model,
                systemPrompt: $this->systemPrompt($mode),
                messages: [
                    new AiChatMessage('user', "Mode: {$mode}\n\nDeterministic brief:\n{$deterministic}\n\nSources JSON:\n{$payload}"),
                ],
                parameters: [
                    'max_tokens' => $mode === 'mail_groups_digest' ? 500 : 400,
                    'temperature' => 0.2,
                ],
            ));
        } catch (Throwable $exception) {
            Log::info('productivity.phrasing_failed', [
                'reason' => $exception::class,
                'mode' => $mode,
            ]);

            return null;
        }

        $text = trim($response->text);
        if ($text === '' || ! ProductivityBriefPhrasing::isComplete($text, $response->finishReason)) {
            return null;
        }

        return $text;
    }

    private function systemPrompt(string $mode): string
    {
        if ($mode === 'mail_groups_digest') {
            return 'You write a spoken-style Russian morning digest of new mail and Telegram groups for Jarvis. Summarize what arrived and what needs attention. Group newsletters, no-reply, receipts, and other noise as a count — do not list them. Do not dump a sender-subject bullet list. Do not invent senders, subjects, snippets, or actions. Use only the provided facts. Max 160 words. No markdown headings.';
        }

        return 'You rewrite a source-grounded Jarvis productivity brief. Keep every listed fact. Do not invent news, memories, or extra tasks. Write concise Russian, max 180 words. No markdown headings.';
    }
}
