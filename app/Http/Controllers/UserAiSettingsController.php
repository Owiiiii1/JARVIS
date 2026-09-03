<?php

namespace App\Http\Controllers;

use App\Models\UserAiSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserAiSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $settings = $user->aiSettings;

        return Inertia::render('Cabinet/AiSettings', [
            'generalPrompt' => $settings?->general_prompt,
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
