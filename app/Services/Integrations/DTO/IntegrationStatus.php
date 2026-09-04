<?php

namespace App\Services\Integrations\DTO;

use App\Enums\IntegrationAccountStatus;

final readonly class IntegrationStatus
{
    /**
     * @param  list<string>  $scopes
     * @param  list<array{key: string, available: bool, label: string}>  $actions
     * @param  list<string>  $scopeLabels
     */
    public function __construct(
        public string $provider,
        public string $displayName,
        public IntegrationAccountStatus $state,
        public string $label,
        public ?string $accountLabel = null,
        public array $scopes = [],
        public ?string $lastSuccessAt = null,
        public ?string $lastErrorAt = null,
        public ?string $diagnosticMessage = null,
        public array $actions = [],
        public bool $configured = true,
        public ?string $connectedAt = null,
        public ?string $tokenHealth = null,
        public array $scopeLabels = [],
        public ?string $lastErrorCode = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'display_name' => $this->displayName,
            'state' => $this->state->value,
            'label' => $this->label,
            'account_label' => $this->accountLabel,
            'scopes' => $this->scopes,
            'last_success_at' => $this->lastSuccessAt,
            'last_error_at' => $this->lastErrorAt,
            'diagnostic_message' => $this->diagnosticMessage,
            'actions' => $this->actions,
            'configured' => $this->configured,
            'connected_at' => $this->connectedAt,
            'token_health' => $this->tokenHealth,
            'scope_labels' => $this->scopeLabels,
            'last_error_code' => $this->lastErrorCode,
        ];
    }
}
