<?php

namespace App\Http\Controllers\Settings;

use App\Enums\WebResearchProvider;
use App\Http\Controllers\Controller;
use App\Services\Users\UserCapability;
use App\Services\WebResearch\WebResearchSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebResearchSettingsController extends Controller
{
    public function __construct(
        private readonly WebResearchSettingsService $settings,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        $request->merge([
            'enabled' => $request->boolean('enabled'),
            'fetch_web_page_enabled' => $request->boolean('fetch_web_page_enabled'),
            'default_recency_days' => $request->filled('default_recency_days')
                ? $request->integer('default_recency_days')
                : null,
        ]);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'provider' => ['required', Rule::enum(WebResearchProvider::class)],
            'max_search_results' => ['required', 'integer', 'min:1', 'max:20'],
            'max_searches_per_turn' => ['required', 'integer', 'min:1', 'max:10'],
            'max_fetches_per_turn' => ['required', 'integer', 'min:0', 'max:10'],
            'max_page_chars' => ['required', 'integer', 'min:500', 'max:20000'],
            'max_total_web_chars' => ['required', 'integer', 'min:1000', 'max:40000'],
            'fetch_web_page_enabled' => ['required', 'boolean'],
            'timeout_seconds' => ['required', 'integer', 'min:2', 'max:60'],
            'default_recency_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $this->settings->update($validated);

        return back()->with('success', 'Web Research settings saved.');
    }

    public function saveTavilyKey(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'tavily_api_key' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $this->settings->setTavilyApiKey($validated['tavily_api_key']);

        return back()->with('success', 'Tavily API key saved.');
    }

    public function clearTavilyKey(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        $this->settings->clearTavilyApiKey();

        return back()->with('success', 'Tavily API key removed. Env WEB_SEARCH_API_KEY remains a fallback if set.');
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();

        if ($user === null || ! $user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)) {
            abort(403);
        }
    }
}
