<?php

namespace Tests\Unit;

use App\Enums\AsyncFailureCategory;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiEmptyResponseException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Exceptions\AiSafetyException;
use App\Services\Memory\Exceptions\MemoryAnalysisException;
use App\Services\Reliability\AsyncFailureClassifier;
use App\Services\Reliability\Exceptions\ClassifiedAsyncException;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\TestCase;

class AsyncFailureClassifierTest extends TestCase
{
    public function test_classifies_transient_timeout_and_rate_limit_as_retryable(): void
    {
        $classifier = new AsyncFailureClassifier;

        $timeout = $classifier->classify(new AiProviderException('Gemini chat request failed with status 504'));
        $this->assertSame(AsyncFailureCategory::ProviderTimeout, $timeout->category);
        $this->assertTrue($timeout->retryable);

        $rateLimit = $classifier->classify(new AiProviderException('Gemini chat request failed with status 429'));
        $this->assertSame(AsyncFailureCategory::ProviderRateLimit, $rateLimit->category);
        $this->assertTrue($rateLimit->retryable);

        $network = $classifier->classify(new ConnectionException('cURL error 7: Failed to connect'));
        $this->assertSame(AsyncFailureCategory::Network, $network->category);
        $this->assertTrue($network->retryable);
    }

    public function test_classifies_auth_safety_and_parse_errors_as_terminal(): void
    {
        $classifier = new AsyncFailureClassifier;

        $auth = $classifier->classify(new AiProviderException('Gemini chat request failed with status 401'));
        $this->assertSame(AsyncFailureCategory::ProviderAuth, $auth->category);
        $this->assertFalse($auth->retryable);

        $config = $classifier->classify(new AiConfigurationException('Analysis AI is not configured.'));
        $this->assertSame(AsyncFailureCategory::ProviderAuth, $config->category);
        $this->assertFalse($config->retryable);

        $safety = $classifier->classify(new AiSafetyException);
        $this->assertSame(AsyncFailureCategory::ProviderSafety, $safety->category);
        $this->assertFalse($safety->retryable);

        $parse = $classifier->classify(new MemoryAnalysisException('Analysis AI did not return a JSON object.'));
        $this->assertSame(AsyncFailureCategory::MalformedProviderResponse, $parse->category);
        $this->assertFalse($parse->retryable);
    }

    public function test_empty_provider_response_is_bounded_retryable(): void
    {
        $classifier = new AsyncFailureClassifier;
        $failure = $classifier->classify(new AiEmptyResponseException);

        $this->assertSame(AsyncFailureCategory::MalformedProviderResponse, $failure->category);
        $this->assertTrue($failure->retryable);
        $this->assertSame('empty_provider_response', $failure->code);
    }

    public function test_historical_safety_message_is_not_retryable(): void
    {
        $classifier = new AsyncFailureClassifier;
        $failure = $classifier->classifyStored('AI response was blocked by the provider safety policy.', null);

        $this->assertSame(AsyncFailureCategory::ProviderSafety, $failure->category);
        $this->assertFalse($failure->retryable);
    }

    public function test_classified_exception_preserves_stale_source(): void
    {
        $classifier = new AsyncFailureClassifier;
        $failure = $classifier->classify(new ClassifiedAsyncException(
            AsyncFailureCategory::StaleSource,
            'stale_source',
        ));

        $this->assertSame(AsyncFailureCategory::StaleSource, $failure->category);
        $this->assertFalse($failure->retryable);
        $this->assertSame('stale_source', $failure->lastError());
    }
}
