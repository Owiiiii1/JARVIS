<?php

namespace App\Enums;

enum VoiceSessionEventType: string
{
    case SessionStarted = 'session.started';
    case StateChanged = 'state.changed';
    case ListeningStarted = 'listening.started';
    case TranscriptPartial = 'transcript.partial';
    case TranscriptFinal = 'transcript.final';
    case AssistantThinking = 'assistant.thinking';
    case AssistantText = 'assistant.text';
    case AudioStarted = 'audio.started';
    case AudioChunk = 'audio.chunk';
    case AudioEnded = 'audio.ended';
    case Interrupted = 'interrupted';
    case Muted = 'muted';
    case Resumed = 'resumed';
    case Error = 'error';
    case SessionEnded = 'session.ended';
}
