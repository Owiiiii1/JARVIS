<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Exceptions\VoiceException;
use PHPUnit\Framework\TestCase;

class VoiceExceptionTest extends TestCase
{
    public function test_tts_failed_without_context_remains_backward_compatible(): void
    {
        $exception = VoiceException::ttsFailed();

        $this->assertSame('voice_tts_failed', $exception->error);
        $this->assertSame(422, $exception->httpStatus);
        $this->assertSame([], $exception->context);
    }

    public function test_tts_failed_stores_bounded_context(): void
    {
        $exception = VoiceException::ttsFailed([
            'reason' => 'http',
            'http_status' => 404,
            'voice_id' => 'user-selected-voice',
            'voice_unavailable' => true,
        ]);

        $this->assertSame(404, $exception->context['http_status']);
        $this->assertSame('user-selected-voice', $exception->context['voice_id']);
        $this->assertTrue($exception->context['voice_unavailable']);
        $this->assertArrayNotHasKey('detail', $exception->context);
        $this->assertArrayNotHasKey('body', $exception->context);
        $this->assertArrayNotHasKey('api_key', $exception->context);
    }
}
