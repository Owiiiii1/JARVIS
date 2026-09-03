<?php

namespace App\Services\Ai;

use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;

final class AiProviderMessageNormalizer
{
    /**
     * @return list<array{role: string, content: string}>
     */
    public static function dialogue(AiChatRequest $request): array
    {
        $messages = array_map(
            static fn (AiChatMessage $message): array => $message->toArray(),
            $request->messages,
        );

        if ($messages === []) {
            return [[
                'role' => 'user',
                'content' => 'Please proceed.',
            ]];
        }

        return $messages;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    public static function ensureStartsWithUser(array $messages): array
    {
        if ($messages === []) {
            return [[
                'role' => 'user',
                'content' => 'Please proceed.',
            ]];
        }

        if (($messages[0]['role'] ?? '') !== 'user') {
            array_unshift($messages, [
                'role' => 'user',
                'content' => '[conversation start]',
            ]);
        }

        return $messages;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    public static function mergeConsecutive(array $messages): array
    {
        $merged = [];

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = $message['content'];
            $last = $merged === [] ? null : $merged[array_key_last($merged)];

            if ($last !== null && $last['role'] === $role) {
                $merged[array_key_last($merged)]['content'] = trim($last['content']."\n\n".$content);

                continue;
            }

            $merged[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $merged;
    }
}
