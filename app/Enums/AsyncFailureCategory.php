<?php

namespace App\Enums;

enum AsyncFailureCategory: string
{
    case ProviderAuth = 'provider_auth';
    case ProviderRateLimit = 'provider_rate_limit';
    case ProviderQuota = 'provider_quota';
    case ProviderTimeout = 'provider_timeout';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderSafety = 'provider_safety';
    case Network = 'network';
    case MalformedProviderResponse = 'malformed_provider_response';
    case Validation = 'validation';
    case MissingSource = 'missing_source';
    case StaleSource = 'stale_source';
    case Ownership = 'ownership';
    case Serialization = 'serialization';
    case Database = 'database';
    case CodeBug = 'code_bug';
    case Unknown = 'unknown';

    public function isTransient(): bool
    {
        return match ($this) {
            self::ProviderRateLimit,
            self::ProviderTimeout,
            self::ProviderUnavailable,
            self::Network,
            self::MalformedProviderResponse => true,
            default => false,
        };
    }
}
