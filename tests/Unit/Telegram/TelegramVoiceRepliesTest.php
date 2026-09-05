<?php

namespace Tests\Unit\Telegram;

use App\Enums\TelegramResponseMode;
use App\Models\Message;
use App\Models\User;
use App\Services\Telegram\Contracts\TelegramDmOutbound;
use App\Services\Telegram\SpokenTextNormalizer;
use App\Services\Telegram\TelegramChatKeyboard;
use App\Services\Telegram\TelegramReplyDeliveryOutcome;
use App\Services\Telegram\TelegramReplyDeliveryService;
use App\Services\Telegram\TelegramVoiceDeliveryDecision;
use App\Services\Telegram\TelegramVoiceSuitabilityPolicy;
use App\Services\Users\ResolvesTelegramResponseMode;
use App\Services\Voice\Contracts\RecordsVoiceMetrics;
use App\Services\Voice\Contracts\ResolvesUserVoice;
use App\Services\Voice\Contracts\SpeechSynthesizer;
use App\Services\Voice\Contracts\StoresEphemeralVoiceAudio;
use App\Services\Voice\DTO\SynthesizedSpeech;
use App\Services\Voice\Exceptions\VoiceException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

class TelegramVoiceRepliesTest extends TestCase
{
    public function test_normalizer_strips_markdown_and_links(): void
    {
        $spoken = (new SpokenTextNormalizer)->normalize(
            "Hello **world** and [docs](https://example.com/path). `code` here.\n```php\necho 1;\n```\n",
        );

        $this->assertStringContainsString('Hello world', $spoken);
        $this->assertStringContainsString('docs', $spoken);
        $this->assertStringNotContainsString('https://', $spoken);
        $this->assertStringNotContainsString('```', $spoken);
        $this->assertStringNotContainsString('**', $spoken);
    }

    public function test_suitability_rejects_large_code_and_long_text(): void
    {
        $policy = new TelegramVoiceSuitabilityPolicy(new SpokenTextNormalizer, 80, 40, 4);

        $code = "Intro\n```\n".str_repeat('x', 80)."\n```\n";
        $this->assertFalse($policy->evaluate($code)->suitable);
        $this->assertSame('code_block', $policy->evaluate($code)->reason);

        $long = str_repeat('слово ', 40);
        $this->assertFalse($policy->evaluate($long)->suitable);
        $this->assertSame('too_long', $policy->evaluate($long)->reason);

        $ok = $policy->evaluate('Короткий ответ без кода.');
        $this->assertTrue($ok->suitable);
        $this->assertNotSame('', $ok->spokenText);
    }

    public function test_auto_mode_is_text_for_text_inbound_and_voice_for_voice_inbound(): void
    {
        $this->assertFalse(TelegramVoiceDeliveryDecision::shouldAttemptVoice(
            TelegramResponseMode::Auto,
            'text',
        ));
        $this->assertTrue(TelegramVoiceDeliveryDecision::shouldAttemptVoice(
            TelegramResponseMode::Auto,
            'voice',
        ));
        $this->assertFalse(TelegramVoiceDeliveryDecision::shouldAttemptVoice(
            TelegramResponseMode::Voice,
            'text',
            forceText: true,
        ));
        $this->assertSame(TelegramResponseMode::Text, TelegramResponseMode::default());
    }

    public function test_text_mode_never_calls_tts(): void
    {
        $tts = new FakeSpeechSynthesizer(configured: true, speech: new SynthesizedSpeech('mp3-bytes', 'audio/mpeg', 'v'));
        $outbound = $this->recordingOutbound();

        $outcome = $this->service($tts, TelegramResponseMode::Text)
            ->deliver($outbound, $this->user(1), 'Привет', null, 'text');

        $this->assertSame(TelegramReplyDeliveryOutcome::Text, $outcome);
        $this->assertSame(0, $tts->calls);
        $this->assertSame(['text'], $outbound->sent);
        $this->assertSame([], $outbound->voices);
    }

    public function test_voice_mode_sends_voice_once_without_duplicate_text(): void
    {
        $tts = new FakeSpeechSynthesizer(configured: true, speech: new SynthesizedSpeech('mp3-bytes', 'audio/mpeg', 'v'));
        $outbound = $this->recordingOutbound();

        $outcome = $this->service($tts, TelegramResponseMode::Voice)
            ->deliver($outbound, $this->user(1), 'Короткий тест.', null, 'text');

        $this->assertSame(TelegramReplyDeliveryOutcome::Voice, $outcome);
        $this->assertSame(1, $tts->calls);
        $this->assertSame(['voice'], $outbound->sent);
        $this->assertCount(1, $outbound->voices);
    }

    public function test_tts_failure_falls_back_to_one_text_message(): void
    {
        $tts = new FakeSpeechSynthesizer(configured: true, exception: VoiceException::ttsFailed());
        $outbound = $this->recordingOutbound();

        $outcome = $this->service($tts, TelegramResponseMode::Voice)
            ->deliver($outbound, $this->user(1), 'Канонический ответ', null, 'text');

        $this->assertSame(TelegramReplyDeliveryOutcome::VoiceFallbackText, $outcome);
        $this->assertSame(['text'], $outbound->sent);
    }

    public function test_send_voice_failure_falls_back_to_text(): void
    {
        $tts = new FakeSpeechSynthesizer(configured: true, speech: new SynthesizedSpeech('mp3-bytes', 'audio/mpeg', 'v'));
        $outbound = $this->recordingOutbound(failVoice: true);

        $outcome = $this->service($tts, TelegramResponseMode::Voice)
            ->deliver($outbound, $this->user(1), 'Канонический ответ', null, 'text');

        $this->assertSame(TelegramReplyDeliveryOutcome::VoiceFallbackText, $outcome);
        $this->assertSame(['voice-attempt', 'text'], $outbound->sent);
    }

    public function test_unconfigured_tts_falls_back_to_text(): void
    {
        $tts = new FakeSpeechSynthesizer(configured: false);
        $outbound = $this->recordingOutbound();

        $outcome = $this->service($tts, TelegramResponseMode::Voice)
            ->deliver($outbound, $this->user(1), 'Канонический ответ', null, 'text');

        $this->assertSame(TelegramReplyDeliveryOutcome::VoiceFallbackText, $outcome);
        $this->assertSame(0, $tts->calls);
        $this->assertSame(['text'], $outbound->sent);
    }

    public function test_unsuitable_content_falls_back_to_text_without_tts(): void
    {
        $tts = new FakeSpeechSynthesizer(configured: true, speech: new SynthesizedSpeech('mp3-bytes', 'audio/mpeg', 'v'));
        $outbound = $this->recordingOutbound();
        $code = "See:\n```\n".str_repeat('line();', 80)."\n```\n";

        $outcome = $this->service($tts, TelegramResponseMode::Voice)
            ->deliver($outbound, $this->user(1), $code, null, 'text');

        $this->assertSame(TelegramReplyDeliveryOutcome::VoiceFallbackText, $outcome);
        $this->assertSame(0, $tts->calls);
        $this->assertSame(['text'], $outbound->sent);
    }

    public function test_user_preferences_are_isolated(): void
    {
        $tts = new FakeSpeechSynthesizer(configured: true, speech: new SynthesizedSpeech('mp3-bytes', 'audio/mpeg', 'v'));
        $prefs = new class implements ResolvesTelegramResponseMode
        {
            public function telegramResponseMode(User $user): TelegramResponseMode
            {
                return $user->id === 1 ? TelegramResponseMode::Voice : TelegramResponseMode::Text;
            }
        };

        $service = $this->serviceWith($tts, $prefs);
        $voiceOut = $this->recordingOutbound();
        $textOut = $this->recordingOutbound();

        $this->assertSame(
            TelegramReplyDeliveryOutcome::Voice,
            $service->deliver($voiceOut, $this->user(1), 'Ответ A', null, 'text'),
        );
        $this->assertSame(
            TelegramReplyDeliveryOutcome::Text,
            $service->deliver($textOut, $this->user(2), 'Ответ B', null, 'text'),
        );
        $this->assertSame(['voice'], $voiceOut->sent);
        $this->assertSame(['text'], $textOut->sent);
    }

    public function test_each_users_voice_is_passed_to_tts(): void
    {
        $tts = new FakeSpeechSynthesizer(configured: true, speech: new SynthesizedSpeech('mp3-bytes', 'audio/mpeg', 'v'));
        $firstUser = $this->user(1);
        $firstUser->voice_id = 'female-voice';
        $secondUser = $this->user(2);
        $secondUser->voice_id = 'male-voice';

        $this->service($tts, TelegramResponseMode::Voice)
            ->deliver($this->recordingOutbound(), $firstUser, 'Первый ответ', null, 'text');
        $this->service($tts, TelegramResponseMode::Voice)
            ->deliver($this->recordingOutbound(), $secondUser, 'Второй ответ', null, 'text');

        $this->assertSame(['female-voice', 'male-voice'], $tts->voiceIds);
    }

    public function test_pending_confirmation_forces_text(): void
    {
        $tts = new FakeSpeechSynthesizer(configured: true, speech: new SynthesizedSpeech('mp3-bytes', 'audio/mpeg', 'v'));
        $outbound = $this->recordingOutbound();
        $assistant = new Message;
        $assistant->forceFill([
            'metadata' => ['pending_confirmation' => ['id' => 'conf-1']],
        ]);

        $outcome = $this->service($tts, TelegramResponseMode::Voice)
            ->deliver($outbound, $this->user(1), 'Подтвердите действие', $assistant, 'text');

        $this->assertSame(TelegramReplyDeliveryOutcome::Text, $outcome);
        $this->assertSame(0, $tts->calls);
        $this->assertSame(['text'], $outbound->sent);
    }

    private function user(int $id): User
    {
        $user = new User;
        $user->id = $id;

        return $user;
    }

    private function service(SpeechSynthesizer $tts, TelegramResponseMode $mode): TelegramReplyDeliveryService
    {
        $prefs = new class($mode) implements ResolvesTelegramResponseMode
        {
            public function __construct(private readonly TelegramResponseMode $mode) {}

            public function telegramResponseMode(User $user): TelegramResponseMode
            {
                return $this->mode;
            }
        };

        return $this->serviceWith($tts, $prefs);
    }

    private function serviceWith(
        SpeechSynthesizer $tts,
        ResolvesTelegramResponseMode $prefs,
    ): TelegramReplyDeliveryService {
        return new TelegramReplyDeliveryService(
            $prefs,
            new class implements ResolvesUserVoice
            {
                public function voiceIdFor(User $user): string
                {
                    return (string) ($user->voice_id ?: 'default-voice');
                }
            },
            $tts,
            new FakeVoiceTempStore,
            new TelegramVoiceSuitabilityPolicy(new SpokenTextNormalizer, 2000, 400, 4),
            new TelegramChatKeyboard,
            new class implements RecordsVoiceMetrics
            {
                public function record(string $event, array $context = []): void {}
            },
        );
    }

    private function recordingOutbound(bool $failVoice = false): RecordingTelegramOutbound
    {
        return new RecordingTelegramOutbound($failVoice);
    }
}

final class FakeSpeechSynthesizer implements SpeechSynthesizer
{
    public int $calls = 0;

    /** @var list<string|null> */
    public array $voiceIds = [];

    public function __construct(
        private readonly bool $configured,
        private readonly ?SynthesizedSpeech $speech = null,
        private readonly ?VoiceException $exception = null,
    ) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function synthesize(string $text, ?string $voiceId = null): SynthesizedSpeech
    {
        $this->calls++;
        $this->voiceIds[] = $voiceId;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->speech === null) {
            throw VoiceException::ttsNotConfigured();
        }

        return $this->speech;
    }
}

final class FakeVoiceTempStore implements StoresEphemeralVoiceAudio
{
    public function putBytes(string $relativePath, string $bytes): string
    {
        return 'voice-temp/telegram/1/a.mp3';
    }

    public function absolutePath(string $relativePath): string
    {
        return '/tmp/a.mp3';
    }

    public function deleteRelative(string $relativePath): void {}
}

final class RecordingTelegramOutbound implements TelegramDmOutbound
{
    /** @var list<string> */
    public array $sent = [];

    /** @var list<string> */
    public array $voices = [];

    public function __construct(private readonly bool $failVoice = false) {}

    public function sendText(
        string $text,
        InlineKeyboardMarkup|ReplyKeyboardMarkup|null $replyMarkup = null,
    ): void {
        $this->sent[] = 'text';
    }

    public function sendVoice(
        string $absolutePath,
        string $filename,
        InlineKeyboardMarkup|ReplyKeyboardMarkup|null $replyMarkup = null,
    ): void {
        $this->sent[] = $this->failVoice ? 'voice-attempt' : 'voice';
        $this->voices[] = $filename;

        if ($this->failVoice) {
            throw new RuntimeException('sendVoice failed');
        }
    }
}
