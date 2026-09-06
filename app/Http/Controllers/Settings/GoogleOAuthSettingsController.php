<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Integrations\Google\GoogleOAuthSettingsService;
use App\Services\Users\UserCapability;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleOAuthSettingsController extends Controller
{
    public function __construct(
        private readonly GoogleOAuthSettingsService $settings,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        $request->merge([
            'client_id' => $this->blankToNull($request->input('client_id')),
            'client_secret' => $this->blankToNull($request->input('client_secret')),
            'redirect_uri' => $this->blankToNull($request->input('redirect_uri')),
        ]);

        $validated = $request->validate([
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'min:8', 'max:512'],
            'redirect_uri' => [
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    if (! $this->isAllowedRedirectUri(trim($value))) {
                        $fail('Redirect URI must be a valid https URL. http is allowed only for localhost.');
                    }
                },
            ],
        ]);

        $this->settings->update([
            'client_id' => $validated['client_id'] ?? null,
            'client_secret' => $validated['client_secret'] ?? null,
            'redirect_uri' => $validated['redirect_uri'] ?? null,
        ]);

        return back()->with('success', 'Google OAuth configuration saved.');
    }

    private function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function isAllowedRedirectUri(string $uri): bool
    {
        if (filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

        if ($scheme === 'https' && $host !== '') {
            return true;
        }

        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true);
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();

        if ($user === null || ! $user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)) {
            abort(403);
        }
    }
}
