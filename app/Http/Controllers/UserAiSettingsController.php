<?php

namespace App\Http\Controllers;

use App\Models\UserAiSetting;
use App\Services\Conversations\ConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserAiSettingsController extends Controller
{
    public function edit(Request $request, ConversationService $conversations): Response
    {
        $user = $request->user();
        $settings = $user->aiSettings;

        return Inertia::render('Cabinet/AiSettings', [
            'generalPrompt' => $settings?->general_prompt,
            'conversations' => $conversations->listForUser($user, ConversationService::CABINET_LIST_LIMIT)
                ->map(static fn ($conversation): array => [
                    'id' => $conversation->id,
                    'title' => $conversation->title,
                    'last_activity_at' => optional($conversation->last_activity_at)?->toIso8601String(),
                    'current' => false,
                ])
                ->values()
                ->all(),
            'user' => [
                'name' => $user->name,
                'timezone' => $user->timezone,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'general_prompt' => ['nullable', 'string', 'max:10000'],
        ]);

        $prompt = filled($validated['general_prompt'] ?? null)
            ? trim((string) $validated['general_prompt'])
            : null;

        UserAiSetting::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['general_prompt' => $prompt === '' ? null : $prompt],
        );

        return back()->with('success', 'General Prompt saved.');
    }
}
