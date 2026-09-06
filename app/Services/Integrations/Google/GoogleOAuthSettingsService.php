<?php

namespace App\Services\Integrations\Google;

use App\Models\GoogleOAuthSetting;
use Illuminate\Support\Facades\Schema;

final class GoogleOAuthSettingsService
{
    public function record(): ?GoogleOAuthSetting
    {
        if (! $this->tableReady()) {
            return null;
        }

        return GoogleOAuthSetting::query()->first();
    }

    public function ensureRecord(): GoogleOAuthSetting
    {
        $record = $this->record();

        if ($record !== null) {
            return $record;
        }

        return GoogleOAuthSetting::query()->create([
            'client_id' => null,
            'client_secret' => null,
            'redirect_uri' => null,
        ]);
    }

    public function clientId(): string
    {
        $stored = trim((string) ($this->record()?->client_id ?? ''));

        if ($stored !== '') {
            return $stored;
        }

        return trim((string) config('integrations.google.client_id'));
    }

    public function clientSecret(): string
    {
        $stored = trim((string) ($this->record()?->client_secret ?? ''));

        if ($stored !== '') {
            return $stored;
        }

        return trim((string) config('integrations.google.client_secret'));
    }

    public function redirectUri(): string
    {
        $stored = trim((string) ($this->record()?->redirect_uri ?? ''));

        if ($stored !== '') {
            return $stored;
        }

        $configured = trim((string) config('integrations.google.redirect_uri'));

        if ($configured !== '') {
            return $configured;
        }

        return $this->defaultRedirectUri();
    }

    public function defaultRedirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/integrations/google/callback';
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function clientIdSource(): ?string
    {
        if (trim((string) ($this->record()?->client_id ?? '')) !== '') {
            return 'admin';
        }

        if (trim((string) config('integrations.google.client_id')) !== '') {
            return 'env';
        }

        return null;
    }

    public function clientSecretSource(): ?string
    {
        if (trim((string) ($this->record()?->client_secret ?? '')) !== '') {
            return 'admin';
        }

        if (trim((string) config('integrations.google.client_secret')) !== '') {
            return 'env';
        }

        return null;
    }

    public function redirectUriSource(): string
    {
        if (trim((string) ($this->record()?->redirect_uri ?? '')) !== '') {
            return 'admin';
        }

        if (trim((string) config('integrations.google.redirect_uri')) !== '') {
            return 'env';
        }

        return 'default';
    }

    /**
     * @return array<string, mixed>
     */
    public function adminPayload(): array
    {
        $secretSource = $this->clientSecretSource();

        return [
            'client_id' => $this->clientId(),
            'client_id_source' => $this->clientIdSource(),
            'has_client_secret' => $secretSource !== null,
            'client_secret_source' => $secretSource,
            'redirect_uri' => trim((string) ($this->record()?->redirect_uri ?? '')),
            'redirect_uri_effective' => $this->redirectUri(),
            'redirect_uri_default' => $this->defaultRedirectUri(),
            'redirect_uri_source' => $this->redirectUriSource(),
            'configured' => $this->isConfigured(),
        ];
    }

    /**
     * @param  array{client_id?: string|null, client_secret?: string|null, redirect_uri?: string|null}  $data
     */
    public function update(array $data): GoogleOAuthSetting
    {
        $record = $this->ensureRecord();
        $record->client_id = $this->nullableTrim($data['client_id'] ?? null);
        $record->redirect_uri = $this->nullableTrim($data['redirect_uri'] ?? null);

        $secret = $this->nullableTrim($data['client_secret'] ?? null);
        if ($secret !== null) {
            $record->client_secret = $secret;
        }

        $record->save();

        return $record->refresh();
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('google_oauth_settings');
        } catch (\Throwable) {
            return false;
        }
    }
}
