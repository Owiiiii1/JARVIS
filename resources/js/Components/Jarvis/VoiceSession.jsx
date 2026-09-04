import { Loader2, Mic, MicOff, PhoneOff, Square, Type } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { VoiceAudioAnalyzer } from '@/voice/audio/VoiceAudioAnalyzer';
import { quietBands, syntheticSpeaking } from '@/voice/audio/syntheticOutput';
import VoiceDemoDrawer from '@/voice/components/VoiceDemoDrawer';
import { isVoiceDemoEnabled } from '@/voice/demo';
import { prefersReducedMotion } from '@/voice/visualization/capabilities';
import JarvisVoiceOrb from '@/voice/visualization/JarvisVoiceOrb';
import { createVoiceVisualizationState } from '@/voice/visualization/VoiceVisualizationState';

const MIME_CANDIDATES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/ogg',
    'audio/mp4',
];

const PROVIDER_CODES = new Set(['voice_stt_not_configured', 'voice_tts_not_configured']);

const STATE_LABELS = {
    connecting: 'Connecting',
    idle: 'Idle',
    listening: 'Listening',
    transcribing: 'Transcribing',
    thinking: 'Thinking',
    speaking: 'Speaking',
    interrupted: 'Interrupted',
    muted: 'Muted',
    error: 'Error',
    ended: 'Ended',
};

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function newClientId() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const rand = (Math.random() * 16) | 0;
        const value = char === 'x' ? rand : (rand & 0x3) | 0x8;

        return value.toString(16);
    });
}

function detectMime() {
    if (typeof MediaRecorder === 'undefined') {
        return null;
    }

    const match = MIME_CANDIDATES.find((type) => {
        try {
            return MediaRecorder.isTypeSupported(type);
        } catch {
            return false;
        }
    });

    return match ?? '';
}

function browserSupported() {
    return Boolean(
        typeof window !== 'undefined'
            && navigator.mediaDevices
            && typeof navigator.mediaDevices.getUserMedia === 'function'
            && typeof MediaRecorder !== 'undefined',
    );
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

function eventError(payload) {
    if (payload?.error) {
        return payload.error;
    }

    const events = payload?.events ?? [];
    const failed = [...events].reverse().find((item) => item?.type === 'error' && item?.payload?.code);

    return failed?.payload?.code ?? null;
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

function friendlyError(code) {
    if (PROVIDER_CODES.has(code)) {
        return 'Speech providers not configured.';
    }
    if (code === 'voice_microphone_unavailable') {
        return 'Microphone is unavailable.';
    }
    if (code === 'voice_audio_format_unsupported') {
        return 'This audio format is not supported.';
    }

    return code || '';
}

/**
 * Workspace Voice client. Orb is visualization-only; session lifecycle stays here.
 */
export default function VoiceSession({ conversationId, onSwitchToText, onTurn, surface = 'jarvis' }) {
    const demoEnabled = isVoiceDemoEnabled();
    const [status, setStatus] = useState('idle');
    const [demoState, setDemoState] = useState(null);
    const [sessionId, setSessionId] = useState(null);
    const [busy, setBusy] = useState(false);
    const [recording, setRecording] = useState(false);
    const [muted, setMuted] = useState(false);
    const [unsupported, setUnsupported] = useState(false);
    const [error, setError] = useState('');
    const [providerNotice, setProviderNotice] = useState('');
    const [transcript, setTranscript] = useState('');
    const [assistantText, setAssistantText] = useState('');
    const [permission, setPermission] = useState('prompt');

    const sessionIdRef = useRef(null);
    const recorderRef = useRef(null);
    const streamRef = useRef(null);
    const chunksRef = useRef([]);
    const playbackRef = useRef(null);
    const sequenceRef = useRef(0);
    const recordStartedAt = useRef(null);
    const mimeRef = useRef('');
    const endingRef = useRef(false);
    const skipSendRef = useRef(false);
    const visualOnlyRef = useRef(false);
    const analyserRef = useRef(null);
    const statusRef = useRef('idle');
    const demoStateRef = useRef(null);
    const mutedRef = useRef(false);
    const sessionPresentRef = useRef(false);
    const vizRef = useRef(createVoiceVisualizationState({ state: 'idle' }));

    const orbState = demoState ?? status;

    useEffect(() => {
        statusRef.current = status;
    }, [status]);
    useEffect(() => {
        demoStateRef.current = demoState;
    }, [demoState]);
    useEffect(() => {
        mutedRef.current = muted;
    }, [muted]);
    useEffect(() => {
        sessionPresentRef.current = Boolean(sessionId);
    }, [sessionId]);

    useEffect(() => {
        setUnsupported(! browserSupported());
        mimeRef.current = detectMime() ?? '';
        analyserRef.current = new VoiceAudioAnalyzer();

        let raf = 0;
        const tick = (now) => {
            const analysis = analyserRef.current?.tick() ?? {
                inputAmplitude: 0,
                outputAmplitude: 0,
                frequencyBands: quietBands(),
                outputBands: quietBands(),
            };
            const visualState = demoStateRef.current ?? statusRef.current;
            const thinking = visualState === 'thinking';
            const speaking = visualState === 'speaking';
            const listening = visualState === 'listening';
            let inputAmplitude = listening && ! thinking ? analysis.inputAmplitude : 0;
            let outputAmplitude = speaking ? analysis.outputAmplitude : 0;
            let frequencyBands = listening ? analysis.frequencyBands : speaking ? analysis.outputBands : quietBands();

            if (speaking && outputAmplitude < 0.05) {
                const syn = syntheticSpeaking(now / 1000);
                outputAmplitude = syn.outputAmplitude;
                frequencyBands = syn.frequencyBands;
            }

            if (thinking) {
                inputAmplitude = 0;
                outputAmplitude = 0;
                frequencyBands = quietBands();
            }

            vizRef.current = createVoiceVisualizationState({
                state: visualState,
                inputAmplitude,
                outputAmplitude,
                frequencyBands,
                connectionState: connectionFrom(statusRef.current, sessionPresentRef.current || demoEnabled),
                isMuted: mutedRef.current || visualState === 'muted',
                reducedMotion: prefersReducedMotion(),
            });
            raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);

        return () => {
            cancelAnimationFrame(raf);
            endingRef.current = true;
            stopPlayback();
            stopCapture();
            analyserRef.current?.dispose();
            analyserRef.current = null;
            const id = sessionIdRef.current;
            if (id) {
                fetch(route(`${surface}.voice.sessions.destroy`, id), {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: jsonHeaders(),
                    keepalive: true,
                }).catch(() => {});
            }
        };
    }, [conversationId, demoEnabled, surface]);

    const applySnapshot = (payload, clientMessageId = null) => {
        const code = eventError(payload);

        if (code && PROVIDER_CODES.has(code)) {
            if (code === 'voice_stt_not_configured') {
                visualOnlyRef.current = true;
                skipSendRef.current = true;
            }
            setProviderNotice('Speech providers not configured.');
        } else if (code) {
            setError(friendlyError(code));
        }

        if (payload?.status && payload.status !== 'error') {
            setStatus(payload.status);
            setMuted(payload.status === 'muted');
        } else if (payload?.status === 'error' && ! (code && PROVIDER_CODES.has(code))) {
            setStatus('error');
        }

        const events = payload?.events ?? [];
        events.forEach((item) => {
            if (item?.type === 'transcript.final' && item.payload?.text) {
                setTranscript(item.payload.text);
            }
            if (item?.type === 'assistant.text' && item.payload?.text) {
                setAssistantText(item.payload.text);
            }
        });

        if (payload?.turn && onTurn) {
            onTurn(payload.turn, null, clientMessageId);
        }

        if (payload?.audio?.base64) {
            playAudio(payload.audio.base64, payload.audio.mime);
        }
    };

    const playAudio = (base64, mime) => {
        stopPlayback();
        try {
            const bytes = Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
            const blob = new Blob([bytes], { type: mime || 'audio/mpeg' });
            const url = URL.createObjectURL(blob);
            const audio = new Audio(url);
            audio.crossOrigin = 'anonymous';
            playbackRef.current = audio;
            analyserRef.current?.connectOutputAudio(audio);
            audio.onended = () => {
                URL.revokeObjectURL(url);
                playbackRef.current = null;
            };
            audio.play().catch(() => {
                URL.revokeObjectURL(url);
            });
        } catch {
            setError(friendlyError('voice_runtime_failed'));
        }
    };

    const stopPlayback = () => {
        const audio = playbackRef.current;
        if (audio) {
            audio.pause();
            audio.src = '';
            playbackRef.current = null;
        }
        analyserRef.current?.disconnectOutput();
    };

    const stopCapture = () => {
        const recorder = recorderRef.current;
        if (recorder && recorder.state !== 'inactive') {
            try {
                recorder.stop();
            } catch {
                // ignore
            }
        }
        recorderRef.current = null;
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
        analyserRef.current?.disconnectInput();
        setRecording(false);
    };

    const attachAnalyser = (stream) => {
        analyserRef.current?.connectInputStream(stream);
    };

    const postJson = async (url, body = {}) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: jsonHeaders(),
            body: JSON.stringify(body),
        });
        const payload = await readJson(response);
        if (! response.ok) {
            throw new Error(payload.error || payload.message || 'voice_runtime_failed');
        }
        return payload;
    };

    const startMicOnly = async () => {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        streamRef.current = stream;
        setPermission('granted');
        attachAnalyser(stream);
        return stream;
    };

    const startVoice = async () => {
        if (busy || unsupported || ! conversationId) {
            return;
        }

        setBusy(true);
        setError('');
        setProviderNotice('');
        setTranscript('');
        setAssistantText('');
        visualOnlyRef.current = false;

        try {
            const stream = await startMicOnly();

            const created = await postJson(route(`${surface}.voice.sessions.store`, conversationId), {
                origin: 'web',
            });
            sessionIdRef.current = created.public_id;
            setSessionId(created.public_id);
            applySnapshot(created);

            const listening = await postJson(route(`${surface}.voice.sessions.listen`, created.public_id));
            applySnapshot(listening);
            beginRecording(stream);
        } catch (caught) {
            stopCapture();
            if (caught?.name === 'NotAllowedError' || caught?.name === 'NotFoundError') {
                setPermission('denied');
                setError(friendlyError('voice_microphone_unavailable'));
            } else if (PROVIDER_CODES.has(caught.message)) {
                visualOnlyRef.current = true;
                setProviderNotice('Speech providers not configured.');
                setStatus('listening');
            } else {
                setError(friendlyError(caught.message || 'voice_runtime_failed'));
                setStatus('error');
            }
        } finally {
            setBusy(false);
        }
    };

    const beginRecording = (stream) => {
        if (visualOnlyRef.current) {
            attachAnalyser(stream);
            setRecording(true);

            return;
        }

        const options = {};
        if (mimeRef.current) {
            options.mimeType = mimeRef.current;
        }

        let recorder;
        try {
            recorder = new MediaRecorder(stream, options);
        } catch {
            recorder = new MediaRecorder(stream);
        }

        chunksRef.current = [];
        recorder.ondataavailable = (event) => {
            if (event.data && event.data.size > 0) {
                chunksRef.current.push(event.data);
            }
        };
        recorder.onstop = () => {
            const blob = new Blob(chunksRef.current, { type: recorder.mimeType || mimeRef.current || 'audio/webm' });
            chunksRef.current = [];
            if (skipSendRef.current) {
                skipSendRef.current = false;
                return;
            }
            if (blob.size > 0 && sessionIdRef.current && ! endingRef.current && ! visualOnlyRef.current) {
                sendUtterance(blob, recorder.mimeType);
            }
        };

        recorderRef.current = recorder;
        recordStartedAt.current = Date.now();
        recorder.start();
        setRecording(true);
        attachAnalyser(stream);
    };

    const sendUtterance = async (blob, mimeType) => {
        const id = sessionIdRef.current;
        if (! id || visualOnlyRef.current) {
            return;
        }

        sequenceRef.current += 1;
        const clientMessageId = newClientId();
        const durationMs = recordStartedAt.current ? Date.now() - recordStartedAt.current : null;
        const form = new FormData();
        form.append('audio', blob, 'utterance.webm');
        form.append('sequence', String(sequenceRef.current));
        form.append('is_final', '1');
        form.append('client_message_id', clientMessageId);
        form.append('channels', '1');
        if (durationMs) {
            form.append('duration_ms', String(durationMs));
        }
        if (mimeType) {
            form.append('mime', mimeType);
        }

        setBusy(true);
        try {
            const response = await fetch(route(`${surface}.voice.sessions.audio`, id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: form,
            });
            const payload = await readJson(response);
            if (! response.ok) {
                throw new Error(payload.error || payload.message || 'voice_runtime_failed');
            }
            applySnapshot(payload, clientMessageId);
        } catch (caught) {
            if (PROVIDER_CODES.has(caught.message)) {
                visualOnlyRef.current = true;
                skipSendRef.current = true;
                setProviderNotice('Speech providers not configured.');
            } else {
                setError(friendlyError(caught.message || 'voice_runtime_failed'));
            }
        } finally {
            setBusy(false);
            if (sessionIdRef.current && streamRef.current && ! muted && ! endingRef.current && ! visualOnlyRef.current) {
                beginRecording(streamRef.current);
            }
        }
    };

    const stopUtterance = () => {
        const recorder = recorderRef.current;
        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
            setRecording(false);
        }
    };

    const handleMic = async () => {
        if (! sessionId) {
            await startVoice();
            return;
        }

        if (recording) {
            stopUtterance();
            return;
        }

        if (streamRef.current && ! muted) {
            beginRecording(streamRef.current);
        }
    };

    const handleMute = async () => {
        const id = sessionIdRef.current;
        if (demoEnabled && ! id) {
            setMuted((current) => ! current);
            setDemoState((current) => (current === 'muted' ? 'idle' : 'muted'));
            if (! muted && streamRef.current) {
                streamRef.current.getAudioTracks().forEach((track) => {
                    track.enabled = false;
                });
            } else if (muted && streamRef.current) {
                streamRef.current.getAudioTracks().forEach((track) => {
                    track.enabled = true;
                });
            }

            return;
        }

        if (! id || busy) {
            return;
        }

        setBusy(true);
        try {
            if (muted) {
                const payload = await postJson(route(`${surface}.voice.sessions.resume`, id));
                applySnapshot(payload);
                if (! streamRef.current) {
                    await startMicOnly();
                }
                const listening = await postJson(route(`${surface}.voice.sessions.listen`, id));
                applySnapshot(listening);
                beginRecording(streamRef.current);
            } else {
                skipSendRef.current = true;
                stopPlayback();
                stopCapture();
                const payload = await postJson(route(`${surface}.voice.sessions.mute`, id));
                applySnapshot(payload);
            }
        } catch (caught) {
            setError(friendlyError(caught.message || 'voice_runtime_failed'));
        } finally {
            setBusy(false);
        }
    };

    const handleInterrupt = async () => {
        if (demoEnabled && demoState) {
            setDemoState('interrupted');
            stopPlayback();
            return;
        }

        const id = sessionIdRef.current;
        if (! id) {
            return;
        }

        stopPlayback();
        try {
            const payload = await postJson(route(`${surface}.voice.sessions.interrupt`, id));
            applySnapshot(payload);
        } catch (caught) {
            setError(friendlyError(caught.message || 'voice_runtime_failed'));
        }
    };

    const endSession = async () => {
        endingRef.current = true;
        skipSendRef.current = true;
        stopPlayback();
        stopCapture();
        const id = sessionIdRef.current;
        sessionIdRef.current = null;
        setSessionId(null);
        setStatus('ended');
        setRecording(false);
        setMuted(false);

        if (id) {
            try {
                await fetch(route(`${surface}.voice.sessions.destroy`, id), {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: jsonHeaders(),
                });
            } catch {
                // ignore
            }
        }
    };

    const handleEnd = async () => {
        await endSession();
        endingRef.current = false;
        setStatus('idle');
        setDemoState(null);
    };

    const handleText = async () => {
        await endSession();
        onSwitchToText?.();
    };

    const handleDemoMic = async () => {
        try {
            await startMicOnly();
            setDemoState((current) => current ?? 'listening');
        } catch {
            setPermission('denied');
            setError(friendlyError('voice_microphone_unavailable'));
        }
    };

    const stateLabel = unsupported
        ? 'Browser not supported'
        : STATE_LABELS[orbState] ?? 'Voice';

    const notice = unsupported
        ? 'This browser cannot capture microphone audio. Use a recent Chrome, Edge, or Firefox, or stay in Text mode.'
        : providerNotice
            ? providerNotice
            : error
                ? error
                : sessionId
                    ? 'Same conversation as Text. Transcripts stay in the thread.'
                    : 'Start Voice requests the microphone. Switching to Text ends the session and keeps this chat.';

    return (
        <div className="jarvis-voice-mode">
            <JarvisVoiceOrb visualizationRef={vizRef} fallbackState={orbState} />
            <div className="jarvis-voice-mode__stage">
                <p className="jarvis-voice-mode__state" aria-live="polite">
                    {stateLabel}
                </p>
                <div className="jarvis-voice-mode__copy">
                    {transcript ? (
                        <p className="jarvis-voice-mode__user">{transcript}</p>
                    ) : (
                        <p className="jarvis-voice-mode__placeholder">Latest phrase</p>
                    )}
                    {assistantText ? (
                        <p className="jarvis-voice-mode__assistant">{assistantText}</p>
                    ) : null}
                    {notice ? (
                        <p className="jarvis-voice-mode__notice">{notice}</p>
                    ) : null}
                    {permission === 'denied' ? (
                        <p className="jarvis-voice-mode__notice">Microphone permission was denied.</p>
                    ) : null}
                </div>
            </div>

            <div className="jarvis-voice-mode__bar">
                <p className="jarvis-voice-mode__meta">
                    Conversation {conversationId ? `#${conversationId}` : ''} stays selected.
                    {busy ? ' Working…' : ''}
                </p>
                <div className="jarvis-voice-mode__controls">
                    <button
                        type="button"
                        disabled={(! sessionId && ! demoEnabled) || busy || unsupported}
                        onClick={handleMute}
                        className="jarvis-voice-btn"
                        aria-label={muted ? 'Unmute microphone' : 'Mute microphone'}
                    >
                        {muted ? <MicOff className="h-4 w-4" /> : <Mic className="h-4 w-4 opacity-70" />}
                    </button>
                    <button
                        type="button"
                        disabled={busy || unsupported || ! conversationId}
                        onClick={handleMic}
                        className={`jarvis-voice-btn jarvis-voice-btn--primary ${recording ? 'is-live' : ''}`}
                        aria-label={sessionId ? (recording ? 'Send utterance' : 'Start listening') : 'Start Voice'}
                    >
                        {busy ? <Loader2 className="h-5 w-5 animate-spin" /> : <Mic className="h-5 w-5" />}
                    </button>
                    <button
                        type="button"
                        disabled={(! sessionId && ! demoEnabled) || busy}
                        onClick={handleInterrupt}
                        className="jarvis-voice-btn"
                        aria-label="Interrupt playback"
                    >
                        <Square className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        disabled={! sessionId && ! demoEnabled}
                        onClick={handleEnd}
                        className="jarvis-voice-btn"
                        aria-label="End voice"
                    >
                        <PhoneOff className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        onClick={handleText}
                        className="jarvis-voice-btn jarvis-voice-btn--text"
                        aria-label="Switch to text"
                    >
                        <Type className="h-4 w-4" />
                        Text
                    </button>
                </div>
            </div>

            <VoiceDemoDrawer
                enabled={demoEnabled}
                state={orbState}
                onState={setDemoState}
                onStartMic={handleDemoMic}
            />
        </div>
    );
}
