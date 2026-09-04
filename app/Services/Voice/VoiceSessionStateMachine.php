<?php

namespace App\Services\Voice;

use App\Enums\VoiceSessionStatus;
use App\Models\VoiceSession;
use App\Services\Voice\Exceptions\VoiceException;

final class VoiceSessionStateMachine
{
    public function transition(VoiceSession $session, VoiceSessionStatus $target): VoiceSession
    {
        $from = $session->status;

        if (! $from->canTransitionTo($target)) {
            throw VoiceException::invalidState($from->value, $target->value);
        }

        if ($from === $target) {
            return $session;
        }

        $session->status = $target;

        if ($target === VoiceSessionStatus::Ended) {
            $session->ended_at = now();
        }

        $session->last_activity_at = now();
        $session->save();

        return $session;
    }
}
