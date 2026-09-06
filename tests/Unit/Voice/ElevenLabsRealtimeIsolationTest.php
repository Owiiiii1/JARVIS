<?php

namespace Tests\Unit\Voice;

use App\Services\Telegram\TelegramReplyDeliveryService;
use App\Services\Telegram\TelegramVoiceInboundService;
use App\Services\Voice\ElevenLabsRealtimeSessionService;
use App\Services\Voice\ElevenLabsRealtimeTurnAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ElevenLabsRealtimeIsolationTest extends TestCase
{
    public function test_telegram_voice_inbound_does_not_depend_on_realtime_session(): void
    {
        $this->assertConstructorAvoidsRealtime(TelegramVoiceInboundService::class);
    }

    public function test_telegram_voice_replies_do_not_depend_on_realtime_session(): void
    {
        $this->assertConstructorAvoidsRealtime(TelegramReplyDeliveryService::class);
    }

    public function test_adapter_reads_last_user_text_only(): void
    {
        $adapter = $this->adapterWithoutContainer();

        $text = $adapter->lastUserText([
            'messages' => [
                ['role' => 'system', 'content' => 'ElevenLabs'],
                ['role' => 'user', 'content' => 'first'],
                ['role' => 'assistant', 'content' => 'reply'],
                ['role' => 'user', 'content' => 'second turn'],
            ],
            'user' => 'ignored',
            'conversation_id' => 99,
        ]);

        $this->assertSame('second turn', $text);
        $this->assertSame('tok', $adapter->sessionTokenFromPayload([
            'elevenlabs_extra_body' => ['jarvis_session_token' => 'tok', 'user_id' => 1],
        ]));
    }

    /**
     * @param  class-string  $class
     */
    private function assertConstructorAvoidsRealtime(string $class): void
    {
        $parameters = (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
        $types = [];

        foreach ($parameters as $parameter) {
            $types[] = (string) $parameter->getType();
        }

        $this->assertNotContains(ElevenLabsRealtimeSessionService::class, $types);
        $this->assertNotContains(ElevenLabsRealtimeTurnAdapter::class, $types);
        $this->assertFalse(
            str_contains(strtolower(implode(' ', $types)), 'realtime'),
            'Telegram path must not take a realtime collaborator.',
        );
    }

    private function adapterWithoutContainer(): ElevenLabsRealtimeTurnAdapter
    {
        return (new ReflectionClass(ElevenLabsRealtimeTurnAdapter::class))
            ->newInstanceWithoutConstructor();
    }
}
