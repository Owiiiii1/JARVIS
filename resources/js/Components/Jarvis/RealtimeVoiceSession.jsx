import { Loader2, Mic, MicOff, PhoneOff, Type } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { resumeSharedAudioContext } from '@/voice/audio/voiceMedia';
import { prefersReducedMotion } from '@/voice/visualization/capabilities';
import JarvisVoiceOrb from '@/voice/visualization/JarvisVoiceOrb';
import { createVoiceVisualizationState } from '@/voice/visualization/VoiceVisualizationState';

const STATE_LABELS = {
    connecting: 'Connecting…',
    listening: 'Listening…',
    user_speaking: 'Говорите…',
    thinking: 'Thinking…',
    speaking: 'Speaking…',
    interrupted: 'Listening…',
    muted: 'Muted',
    error: 'Error',
    ended: 'Ended',
};

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function jsonHeaders() {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
    };
}

async function readJson(response) {
    return response.json().catch(() => ({}));
}

async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: jsonHeaders(),
        body: JSON.stringify(body ?? {}),
    });
    const payload = await readJson(response);

    if (! response.ok) {
        const error = new Error(payload.error || payload.message || 'voice_realtime_unavailable');
        error.code = payload.error;
        error.payload = payload;
        throw error;
    }

    return payload;
}

function mapSdkMode(mode, muted) {
    if (muted) {
        return 'muted';
    }

    if (mode === 'speaking') {
        return 'speaking';
    }

    return 'listening';
}

function connectionFrom(status, sessionId) {
    if (status === 'error') {
        return 'error';
    }
    if (status === 'connecting') {
        return 'connecting';
    }
    if (status === 'ended' || ! sessionId) {
        return 'disconnected';
    }

    return 'connected';
}

export default function RealtimeVoiceSession({
    conversationId,
    surface,
    realtime = {},
    onSwitchToText,
    onSwitchToPtt,
    onTurn,
}) {
    const [status, setStatus] = useState('connecting');
    const [muted, setMuted] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [transcript, setTranscript] = useState('');
    const [assistantText, setAssistantText] = useState('');
    const [sessionId, setSessionId] = useState(null);
    const vizRef = useRef(createVoiceVisualizationState({ state: 'connecting' }));
    const conversationRef = useRef(null);
    const sessionIdRef = useRef(null);
    const genRef = useRef(0);
    const seenTurnRef = useRef(null);
    const endingRef = useRef(false);
    const mutedRef = useRef(false);
    const statusRef = useRef('connecting');
    const connectStartedRef = useRef(0);

    mutedRef.current = muted;
    statusRef.current = status;

    const configured = Boolean(realtime.configured);
    const fallbackLabel = realtime.fallback_label || 'Переключиться на Рацию';

    useEffect(() => {
        const gen = ++genRef.current;
        endingRef.current = false;
        seenTurnRef.current = null;
        connectStartedRef.current = performance.now();

        let raf = 0;
        const tick = () => {
            const conversation = conversationRef.current;
            let inputAmplitude = 0;
            let outputAmplitude = 0;

            try {
                if (conversation && typeof conversation.getInputVolume === 'function') {
                    inputAmplitude = Number(conversation.getInputVolume()) || 0;
                }
                if (conversation && typeof conversation.getOutputVolume === 'function') {
                    outputAmplitude = Number(conversation.getOutputVolume()) || 0;
                }
            } catch {
                inputAmplitude = 0;
            }

            let visualState = statusRef.current;

            if (mutedRef.current) {
                visualState = 'muted';
            } else if (visualState === 'listening' && inputAmplitude > 0.18) {
                visualState = 'user_speaking';
            }

            vizRef.current = createVoiceVisualizationState({
                state: visualState === 'speaking' ? 'speaking' : visualState,
                inputAmplitude,
                outputAmplitude,
                connectionState: connectionFrom(statusRef.current, Boolean(sessionIdRef.current)),
                isMuted: mutedRef.current,
                reducedMotion: prefersReducedMotion(),
            });
            raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);

        if (! configured) {
            setStatus('error');
            setError('Диалог Beta не настроен.');
        } else if (conversationId) {
            startSession(gen);
        } else {
            setStatus('error');
            setError('Choose a chat first.');
        }

        return () => {
            genRef.current += 1;
            endingRef.current = true;
            cancelAnimationFrame(raf);
            stopSdk();
            const id = sessionIdRef.current;
            sessionIdRef.current = null;
            if (id) {
                fetch(route(`${surface}.voice.realtime.session.destroy`, id), {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: jsonHeaders(),
                    keepalive: true,
                }).catch(() => {});
            }
        };
        // Conversation switch ends the previous realtime session; a new one is created for the new chat.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [conversationId, surface, configured]);

    useEffect(() => {
        if (! sessionId || status === 'error' || status === 'ended') {
            return undefined;
        }

        const timer = window.setInterval(() => {
            pollTurn();
        }, 1500);

        return () => window.clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sessionId, status, surface]);

    const stopSdk = async () => {
        const conversation = conversationRef.current;
        conversationRef.current = null;
        if (! conversation) {
            return;
        }

        try {
            await conversation.endSession();
        } catch {
            // Already closed.
        }
    };

    const startSession = async (gen) => {
        setBusy(true);
        setError('');
        setTranscript('');
        setAssistantText('');
        setStatus('connecting');

        try {
            await resumeSharedAudioContext();
            const created = await postJson(route(`${surface}.voice.realtime.session.store`, conversationId), {});
            if (gen !== genRef.current) {
                return;
            }

            sessionIdRef.current = created.public_id;
            setSessionId(created.public_id);

            const { Conversation } = await import('@elevenlabs/client');
            const extraBody = created.extra_body ?? { jarvis_session_token: created.adapter_token };
            const conversation = await Conversation.startSession({
                signedUrl: created.signed_url,
                connectionType: created.connection_type || 'websocket',
                customLlmExtraBody: extraBody,
                overrides: created.overrides ?? {},
                onConnect: ({ conversationId: externalId } = {}) => {
                    if (gen !== genRef.current) {
                        return;
                    }
                    setStatus('listening');
                    const connectMs = Math.round(performance.now() - connectStartedRef.current);
                    postJson(route(`${surface}.voice.realtime.session.metrics`, created.public_id), {
                        session_connect_ms: connectMs,
                        external_conversation_id: externalId || conversationRef.current?.getId?.() || undefined,
                    }).catch(() => {});
                },
                onDisconnect: () => {
                    if (gen !== genRef.current || endingRef.current) {
                        return;
                    }
                    setStatus('ended');
                },
                onError: () => {
                    if (gen !== genRef.current) {
                        return;
                    }
                    setStatus('error');
                    setError('Диалог Beta недоступен.');
                },
                onModeChange: (payload) => {
                    if (gen !== genRef.current) {
                        return;
                    }
                    const mode = payload?.mode ?? payload;
                    setStatus(mapSdkMode(mode, mutedRef.current));
                },
                onInterruption: () => {
                    if (gen !== genRef.current || mutedRef.current) {
                        return;
                    }
                    setStatus('interrupted');
                },
                onMessage: (payload) => {
                    if (gen !== genRef.current) {
                        return;
                    }
                    applySdkMessage(payload);
                },
            });

            if (gen !== genRef.current) {
                try {
                    await conversation.endSession();
                } catch {
                    // Replaced.
                }
                return;
            }

            conversationRef.current = conversation;
        } catch (caught) {
            if (gen !== genRef.current) {
                return;
            }
            setStatus('error');
            setError(caught.code === 'voice_realtime_not_configured'
                ? 'Диалог Beta не настроен.'
                : 'Диалог Beta недоступен.');
        } finally {
            if (gen === genRef.current) {
                setBusy(false);
            }
        }
    };

    const applySdkMessage = (payload) => {
        const source = payload?.source ?? payload?.role ?? '';
        const text = payload?.message ?? payload?.text ?? '';
        const final = payload?.final ?? payload?.isFinal ?? true;

        if (! text) {
            return;
        }

        if (source === 'user' || source === 'human') {
            setTranscript(text);
            if (! final) {
                setStatus((current) => (current === 'speaking' ? current : 'user_speaking'));
            }
            return;
        }

        if (source === 'ai' || source === 'agent' || source === 'assistant') {
            setAssistantText(text);
        }
    };

    const pollTurn = async () => {
        const id = sessionIdRef.current;
        if (! id || endingRef.current) {
            return;
        }

        try {
            const response = await fetch(route(`${surface}.voice.realtime.session.show`, id), {
                credentials: 'same-origin',
                headers: jsonHeaders(),
            });
            const payload = await readJson(response);
            const turn = payload?.turn;
            const inboundId = turn?.inbound?.id;

            if (inboundId && inboundId !== seenTurnRef.current && onTurn) {
                seenTurnRef.current = inboundId;
                onTurn(turn, null, String(inboundId));
            }
        } catch {
            // Poll is best-effort.
        }
    };

    const handleMute = async () => {
        const next = ! muted;
        setMuted(next);
        try {
            conversationRef.current?.setMicMuted?.(next);
        } catch {
            // SDK mute is best-effort.
        }
        if (next) {
            setStatus('muted');
        } else if (status === 'muted') {
            setStatus('listening');
        }
    };

    const handleEnd = async () => {
        endingRef.current = true;
        await stopSdk();
        const id = sessionIdRef.current;
        sessionIdRef.current = null;
        setSessionId(null);
        if (id) {
            fetch(route(`${surface}.voice.realtime.session.destroy`, id), {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: jsonHeaders(),
            }).catch(() => {});
        }
        onSwitchToText?.();
    };

    const listeningLike = status === 'listening' || status === 'user_speaking' || status === 'interrupted';
    const stateLabel = STATE_LABELS[status] ?? 'Voice';
    const notice = error
        ? error
        : (listeningLike && ! muted ? 'Говорите свободно. Кнопку держать не нужно.' : '');

    return (
        <div className="jarvis-voice-mode">
            <JarvisVoiceOrb visualizationRef={vizRef} fallbackState={muted ? 'muted' : status} />
            <div className="jarvis-voice-mode__stage">
                <p className="jarvis-voice-mode__state" aria-live="polite">
                    {stateLabel}
                </p>
                <div className="jarvis-voice-mode__copy">
                    {transcript ? (
                        <p className="jarvis-voice-mode__user">{transcript}</p>
                    ) : (
                        <p className="jarvis-voice-mode__placeholder">
                            {configured ? 'Слушаю…' : 'Диалог Beta не настроен'}
                        </p>
                    )}
                    {assistantText ? (
                        <p className="jarvis-voice-mode__assistant">{assistantText}</p>
                    ) : null}
                    {notice ? (
                        <p className="jarvis-voice-mode__notice">{notice}</p>
                    ) : null}
                    {status === 'error' ? (
                        <button
                            type="button"
                            className="jarvis-voice-btn jarvis-voice-btn--text mt-3"
                            onClick={onSwitchToPtt}
                        >
                            {fallbackLabel}
                        </button>
                    ) : null}
                </div>
            </div>

            <div className="jarvis-voice-mode__bar">
                <p className="jarvis-voice-mode__meta">{busy ? 'Working…' : 'Диалог Beta'}</p>
                <div className="jarvis-voice-mode__controls">
                    <button
                        type="button"
                        disabled={! sessionId || busy}
                        onClick={handleMute}
                        className={`jarvis-voice-btn jarvis-voice-btn--primary ${muted ? '' : 'is-live'}`}
                        aria-label={muted ? 'Unmute microphone' : 'Mute microphone'}
                    >
                        {busy && ! sessionId ? <Loader2 className="h-5 w-5 animate-spin" /> : (muted ? <MicOff className="h-5 w-5" /> : <Mic className="h-5 w-5" />)}
                    </button>
                    <button
                        type="button"
                        className="jarvis-voice-btn"
                        onClick={onSwitchToPtt}
                        aria-label="Switch to push-to-talk"
                    >
                        Рация
                    </button>
                    <button
                        type="button"
                        disabled={! sessionId && status !== 'error'}
                        onClick={handleEnd}
                        className="jarvis-voice-btn"
                        aria-label="End voice"
                    >
                        <PhoneOff className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        onClick={handleEnd}
                        className="jarvis-voice-btn jarvis-voice-btn--text"
                        aria-label="Switch to text"
                    >
                        <Type className="h-4 w-4" />
                        Text
                    </button>
                </div>
            </div>
        </div>
    );
}
