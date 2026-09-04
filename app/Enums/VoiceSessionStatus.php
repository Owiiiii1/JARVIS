<?php

namespace App\Enums;

enum VoiceSessionStatus: string
{
    case Connecting = 'connecting';
    case Idle = 'idle';
    case Listening = 'listening';
    case Transcribing = 'transcribing';
    case Thinking = 'thinking';
    case Speaking = 'speaking';
    case Interrupted = 'interrupted';
    case Muted = 'muted';
    case Error = 'error';
    case Ended = 'ended';

    /**
     * @return list<self>
     */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::Connecting => [self::Idle, self::Listening, self::Error],
            self::Idle => [self::Listening, self::Muted, self::Ended],
            self::Listening => [self::Transcribing, self::Interrupted, self::Muted, self::Ended, self::Error],
            self::Transcribing => [self::Thinking, self::Listening, self::Error, self::Ended],
            self::Thinking => [self::Speaking, self::Interrupted, self::Error, self::Ended],
            self::Speaking => [self::Listening, self::Interrupted, self::Muted, self::Ended, self::Error],
            self::Interrupted => [self::Listening, self::Thinking, self::Ended],
            self::Muted => [self::Idle, self::Listening, self::Ended],
            self::Error => [self::Ended],
            self::Ended => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return in_array($target, $this->allowedTargets(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Ended;
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }
}
