<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationAccountStatus;
use App\Enums\UserRole;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Users\UserCapability;

final class IntegrationAccountService
{
    public function __construct(
        private readonly IntegrationRegistry $registry,
    ) {}

    public function getActiveAccount(User $user, string $provider): ?IntegrationAccount
    {
        $this->assertOwner($user);

        return IntegrationAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('status', IntegrationAccountStatus::Connected)
            ->orderByDesc('connected_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  list<string>|null  $scopes
     * @param  array<string, mixed>|null  $metadata
     */
    public function upsertAccount(
        User $user,
        string $provider,
        ?string $externalAccountId = null,
        ?string $externalAccountEmail = null,
        IntegrationAccountStatus $status = IntegrationAccountStatus::Disconnected,
        ?array $scopes = null,
        ?array $metadata = null,
    ): IntegrationAccount {
        $this->assertOwner($user);

        $externalId = $externalAccountId ?? '';

        $account = IntegrationAccount::query()->firstOrNew([
            'user_id' => $user->id,
            'provider' => $provider,
            'external_account_id' => $externalId,
        ]);

        $account->fill([
            'external_account_email' => $externalAccountEmail,
            'status' => $status,
            'scopes' => $scopes,
            'metadata' => $metadata,
        ]);
        $account->save();

        return $account;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function setCredentials(IntegrationAccount $account, array $credentials): void
    {
        $account->credentials_encrypted = $credentials;
        $account->save();
    }

    /**
     * Adapter-only. Never pass the result to UI, logs, or Inertia.
     *
     * @return array<string, mixed>
     */
    public function getCredentials(IntegrationAccount $account): array
    {
        return is_array($account->credentials_encrypted) ? $account->credentials_encrypted : [];
    }

    public function markConnected(IntegrationAccount $account): void
    {
        $account->forceFill([
            'status' => IntegrationAccountStatus::Connected,
            'connected_at' => now(),
            'disconnected_at' => null,
            'last_error_code' => null,
        ])->save();
    }

    public function markError(IntegrationAccount $account, string $code): void
    {
        $account->forceFill([
            'status' => IntegrationAccountStatus::Error,
            'last_error_at' => now(),
            'last_error_code' => $code,
        ])->save();
    }

    public function markRevoked(IntegrationAccount $account): void
    {
        $account->forceFill([
            'status' => IntegrationAccountStatus::Revoked,
            'disconnected_at' => now(),
            'credentials_encrypted' => null,
        ])->save();
    }

    public function recordSuccess(IntegrationAccount $account): void
    {
        $account->forceFill([
            'last_used_at' => now(),
            'last_success_at' => now(),
            'last_error_code' => null,
        ])->save();
    }

    public function recordError(IntegrationAccount $account, string $code): void
    {
        $account->forceFill([
            'last_used_at' => now(),
            'last_error_at' => now(),
            'last_error_code' => $code,
        ])->save();
    }

    public function disconnect(IntegrationAccount $account): void
    {
        $provider = $this->registry->get($account->provider);
        if ($provider instanceof IntegrationProvider) {
            $provider->disconnect($account);
        }

        $account->forceFill([
            'status' => IntegrationAccountStatus::Disconnected,
            'disconnected_at' => now(),
            'credentials_encrypted' => null,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function safeSummary(IntegrationAccount $account): array
    {
        return [
            'id' => $account->id,
            'provider' => $account->provider,
            'status' => $account->status instanceof IntegrationAccountStatus
                ? $account->status->value
                : (string) $account->status,
            'external_account_email' => $account->external_account_email,
            'scopes' => $account->scopes ?? [],
            'connected_at' => optional($account->connected_at)?->toIso8601String(),
            'last_used_at' => optional($account->last_used_at)?->toIso8601String(),
            'last_success_at' => optional($account->last_success_at)?->toIso8601String(),
            'last_error_at' => optional($account->last_error_at)?->toIso8601String(),
            'last_error_code' => $account->last_error_code,
        ];
    }

    private function assertOwner(User $user): void
    {
        if (! $user->isActive()
            || $user->role !== UserRole::Owner
            || ! $user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)) {
            throw new IntegrationException('forbidden', 'Integrations are owner-only.');
        }
    }
}
