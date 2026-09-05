<?php

namespace App\Services\Telegram;

use App\Models\ChannelIdentity;
use App\Models\Message;
use App\Models\User;
use App\Services\Telegram\Contracts\TelegramDmOutbound;
use App\Services\Users\ResolvesTelegramResponseMode;
use App\Services\Voice\Contracts\RecordsVoiceMetrics;
use App\Services\Voice\Contracts\SpeechSynthesizer;
use App\Services\Voice\Contracts\StoresEphemeralVoiceAudio;
use App\Services\Voice\DTO\SynthesizedSpeech;
use App\Services\Voice\Exceptions\VoiceException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use Throwable;

final class TelegramReplyDeliveryService
{
    public function __construct(
        private readonly ResolvesTelegramResponseMode $preferences,
        private readonly SpeechSynthesizer $tts,
        private readonly StoresEphemeralVoiceAudio $tempAudio,
        private readonly TelegramVoiceSuitabilityPolicy $suitability,
        private readonly TelegramChatKeyboard $keyboard,
        private readonly RecordsVoiceMetrics $metrics,
        private readonly int $maxAudioBytes = 2_000_000,
    ) {}

    public function deliverAssistantReply(
        Nutgram $bot,
        ChannelIdentity $identity,
        User $user,
        string $canonicalText,
        ?Message $assistant,
        string $inboundModality = 'text',
    ): TelegramReplyDeliveryOutcome {
        $outbound = new TelegramNutgramDmOutbound($bot, $identity);

        return $this->deliver(
            $outbound,
            $user,
            $canonicalText,
            $assistant,
            $inboundModality,
        );
    }

    public function deliver(
        TelegramDmOutbound $outbound,
        User $user,
        string $canonicalText,
        ?Message $assistant,
        string $inboundModality = 'text',
    ): TelegramReplyDeliveryOutcome {
        $pendingId = $assistant?->metadata['pending_confirmation']['id'] ?? null;
        $forceText = is_string($pendingId) && $pendingId !== '';
        $markup = $forceText
            ? $this->keyboard->confirmation($pendingId)
            : $this->keyboard->menu();

        $mode = $this->preferences->telegramResponseMode($user);
        $attemptVoice = TelegramVoiceDeliveryDecision::shouldAttemptVoice(
            $mode,
            $inboundModality,
            $forceText,
        );

        if (! $attemptVoice) {
            $this->sendTextSafely($outbound, $canonicalText, $markup);

            return TelegramReplyDeliveryOutcome::Text;
        }

        $suitability = $this->suitability->evaluate($canonicalText);

        if (! $suitability->suitable) {
            $this->recordFallback($user, $suitability->reason ?? 'unsuitable');
            $this->sendTextSafely($outbound, $canonicalText, $markup);

            return TelegramReplyDeliveryOutcome::VoiceFallbackText;
        }

        if (! $this->tts->isConfigured()) {
            $this->recordFallback($user, 'tts_not_configured');
            $this->sendTextSafely($outbound, $canonicalText, $markup);

            return TelegramReplyDeliveryOutcome::VoiceFallbackText;
        }

        try {
            $speech = $this->tts->synthesize($suitability->spokenText);
            $format = $this->compatibleFormat($speech);

            if ($format === null) {
                $this->recordFallback($user, 'incompatible_audio');
                $this->sendTextSafely($outbound, $canonicalText, $markup);

                return TelegramReplyDeliveryOutcome::VoiceFallbackText;
            }

            $maxBytes = max(1024, $this->maxAudioBytes);

            if (strlen($speech->bytes) > $maxBytes) {
                $this->recordFallback($user, 'audio_too_large');
                $this->sendTextSafely($outbound, $canonicalText, $markup);

                return TelegramReplyDeliveryOutcome::VoiceFallbackText;
            }

            $relative = $this->tempAudio->putBytes(
                sprintf('telegram/%d/%s%s', $user->id, bin2hex(random_bytes(16)), $format['extension']),
                $speech->bytes,
            );
            $absolute = $this->tempAudio->absolutePath($relative);

            try {
                $outbound->sendVoice($absolute, 'reply'.$format['extension'], $markup);
            } finally {
                $this->tempAudio->deleteRelative($relative);
            }

            $this->metrics->record('telegram.voice.sent', [
                'user_id' => $user->id,
                'mode' => $mode->value,
                'mime' => $format['mime'],
                'byte_length' => strlen($speech->bytes),
            ]);

            return TelegramReplyDeliveryOutcome::Voice;
        } catch (VoiceException $exception) {
            $this->recordFallback($user, $exception->error);
            $this->sendTextSafely($outbound, $canonicalText, $markup);

            return TelegramReplyDeliveryOutcome::VoiceFallbackText;
        } catch (Throwable) {
            $this->recordFallback($user, 'send_or_temp_failed');
            $this->sendTextSafely($outbound, $canonicalText, $markup);

            return TelegramReplyDeliveryOutcome::VoiceFallbackText;
        }
    }

    /**
     * @return array{mime: string, extension: string}|null
     */
    private function compatibleFormat(SynthesizedSpeech $speech): ?array
    {
        $mime = strtolower(trim($speech->mime));

        return match (true) {
            in_array($mime, ['audio/mpeg', 'audio/mp3', 'audio/mpeg3'], true) => [
                'mime' => 'audio/mpeg',
                'extension' => '.mp3',
            ],
            in_array($mime, ['audio/ogg', 'audio/opus'], true) => [
                'mime' => 'audio/ogg',
                'extension' => '.ogg',
            ],
            in_array($mime, ['audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/aac'], true) => [
                'mime' => 'audio/mp4',
                'extension' => '.m4a',
            ],
            default => null,
        };
    }

    private function sendTextSafely(
        TelegramDmOutbound $outbound,
        string $text,
        InlineKeyboardMarkup|ReplyKeyboardMarkup|null $markup,
    ): void {
        try {
            $outbound->sendText($text, $markup);
        } catch (Throwable) {
            // Outbound Telegram failures must not fail webhook processing.
        }
    }

    private function recordFallback(User $user, string $reason): void
    {
        $this->metrics->record('telegram.voice.fallback', [
            'user_id' => $user->id,
            'reason' => $reason,
        ]);
    }
}
