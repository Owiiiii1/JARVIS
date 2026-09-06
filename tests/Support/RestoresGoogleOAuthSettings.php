<?php

namespace Tests\Support;

use App\Models\GoogleOAuthSetting;

trait RestoresGoogleOAuthSettings
{
    /** @var array{client_id: ?string, client_secret: ?string, redirect_uri: ?string}|null */
    private ?array $googleOAuthSettingSnapshot = null;

    private bool $googleOAuthSettingExisted = false;

    private function snapshotGoogleOAuthSettings(): void
    {
        $record = GoogleOAuthSetting::query()->first();
        $this->googleOAuthSettingExisted = $record !== null;
        $this->googleOAuthSettingSnapshot = $record === null ? null : [
            'client_id' => $record->client_id,
            'client_secret' => $record->client_secret,
            'redirect_uri' => $record->redirect_uri,
        ];
    }

    private function restoreGoogleOAuthSettings(): void
    {
        $record = GoogleOAuthSetting::query()->first();

        if (! $this->googleOAuthSettingExisted) {
            $record?->delete();
            $this->googleOAuthSettingSnapshot = null;

            return;
        }

        if ($record === null) {
            GoogleOAuthSetting::query()->create($this->googleOAuthSettingSnapshot ?? []);
        } else {
            $record->fill($this->googleOAuthSettingSnapshot ?? [])->save();
        }

        $this->googleOAuthSettingSnapshot = null;
    }
}
