<?php

namespace App\Http\Controllers\Settings;

use App\Enums\IntegrationAccountStatus;
use App\Http\Controllers\Controller;
use App\Models\IntegrationAccount;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GoogleConnectionService;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Users\UserCapability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleOAuthController extends Controller
{
    public function __construct(
        private readonly GoogleConnectionService $connections,
        private readonly GoogleOAuthService $oauth,
    ) {}

    public function connect(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        $additionalScopes = [];
        if ($request->query('intent') === 'calendar') {
            $additionalScopes = $this->oauth->calendarScopes();
        }

        try {
            return redirect()->away($this->connections->authorizationUrl($request->user(), $additionalScopes));
        } catch (IntegrationException $exception) {
            return $this->backToIntegrations($exception);
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        try {
            $this->connections->complete(
                $request->user(),
                $request->query('state'),
                $request->query('code'),
                $request->query('error'),
            );

            return redirect()
                ->route('settings.index', ['tab' => 'integrations'])
                ->with('success', 'Google account connected.');
        } catch (IntegrationException $exception) {
            return $this->backToIntegrations($exception);
        }
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);
        $owner = $request->user();

        $account = IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', 'google')
            ->whereIn('status', [
                IntegrationAccountStatus::Connected,
                IntegrationAccountStatus::Error,
                IntegrationAccountStatus::Revoked,
            ])
            ->orderByDesc('connected_at')
            ->orderByDesc('id')
            ->first();

        if ($account === null) {
            return redirect()
                ->route('settings.index', ['tab' => 'integrations'])
                ->with('error', 'No Google account is connected.');
        }

        try {
            $revokedRemotely = $this->connections->disconnect($owner, $account);
        } catch (IntegrationException $exception) {
            return $this->backToIntegrations($exception);
        }

        $redirect = redirect()->route('settings.index', ['tab' => 'integrations'])
            ->with('success', 'Google account disconnected.');

        if (! $revokedRemotely) {
            $redirect->with('warning', 'Google could not be notified. Local credentials were removed.');
        }

        return $redirect;
    }

    private function backToIntegrations(IntegrationException $exception): RedirectResponse
    {
        return redirect()
            ->route('settings.index', ['tab' => 'integrations'])
            ->with('error', $this->safeMessage($exception));
    }

    private function safeMessage(IntegrationException $exception): string
    {
        return match ($exception->error) {
            'configuration_missing' => 'Google OAuth is not configured.',
            'oauth_access_denied' => 'Google authorization was cancelled.',
            'oauth_invalid_state' => 'Google authorization could not be verified. Try connecting again.',
            'refresh_revoked' => 'Google access was revoked. Reconnect required.',
            'google_unavailable' => 'Google is temporarily unavailable.',
            default => 'Google authorization failed.',
        };
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();

        if ($user === null || ! $user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)) {
            abort(403);
        }
    }
}
