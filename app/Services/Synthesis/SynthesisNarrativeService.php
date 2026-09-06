<?php

namespace App\Services\Synthesis;

use App\Enums\AiRoleKey;
use App\Models\AiRoleSetting;
use App\Models\User;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Synthesis\DTO\FactPack;
use App\Services\Synthesis\DTO\SynthesisResult;
use Throwable;

final class SynthesisNarrativeService
{
    public function __construct(
        private readonly AiConfigurationResolver $resolver,
        private readonly AiChatGateway $gateway,
    ) {}

    public function summarize(User $user, FactPack $pack, SynthesisResult $result): ?string
    {
        unset($user);

        try {
            $configuration = $this->resolver->resolveAnalysis();
        } catch (Throwable) {
            return null;
        }

        if (! $configuration instanceof AiRoleSetting || $configuration->roleKey() !== AiRoleKey::OwnerAnalysis) {
            // Analysis role is preferred; any configured analysis mapping still works.
        }

        $facts = [
            'type' => $result->type->value,
            'summary_hints' => [
                'blockers' => array_map(static fn ($item) => $item->title, array_slice($result->blockers, 0, 5)),
                'waiting' => array_map(static fn ($item) => $item->title, array_slice($result->waitingFor, 0, 5)),
                'attention' => array_map(static fn ($item) => $item->title, array_slice($result->attention, 0, 5)),
                'changes' => array_map(static fn ($item) => $item->title, array_slice($result->recentChanges, 0, 8)),
            ],
            'conflicts' => $result->conflicts,
            'freshness' => $result->freshness,
        ];

        try {
            $response = $this->gateway->chat($configuration, new AiChatRequest(
                model: (string) $configuration->model,
                systemPrompt: implode("\n", [
                    'Write a compact factual narrative from the JSON fact pack.',
                    'Do not invent people, dates, or status.',
                    'If conflicts exist, mention them.',
                    'Max 80 words. Russian if the titles are Russian, otherwise English.',
                    'No secrets, no full email bodies.',
                ]),
                messages: [new AiChatMessage('user', json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')],
                parameters: [
                    'temperature' => 0.1,
                    'max_tokens' => max(80, (int) config('synthesis.narrative_max_tokens', 400)),
                ],
            ));
        } catch (Throwable) {
            return null;
        }

        $text = trim((string) $response->text);

        return $text !== '' ? mb_substr($text, 0, 800) : null;
    }
}
