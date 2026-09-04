import OrbPlaceholder from '@/Components/Jarvis/OrbPlaceholder';
import { Loader2, Mic, MicOff, PhoneOff, Type } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const MIME_CANDIDATES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/ogg',
    'audio/mp4',
];

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

/**
 * M23 Voice Runtime client boundary. Same conversation_id as Text mode.
 * Final Orb (Three.js) is M24. Microphone starts only after Start Voice.
 */
export default function VoiceSession({ conversationId, onSwitchToText, onTurn }) {
    const [status, setStatus] = useState('idle');
    const [sessionId, setSessionId] = useState(null);
    const [busy, setBusy] = useState(false);
    const [recording, setRecording] = useState(false);
    const [muted, setMuted] = useState(false);
    const [unsupported, setUnsupported] = useState(false);
    const [error, setError] = useState('');
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

    useEffect(() => {
        setUnsupported(!browserSupported());
        mimeRef.current = detectMime() ?? '';

        return () => {
            endingRef.current = true;
            stopPlayback();
            stopCapture();
            const id = sessionIdRef.current;
            if (id) {
                fetch(route('jarvis.voice.sessions.destroy', id), {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: jsonHeaders(),
                    keepalive: true,
                }).catch(() => {});
            }
        };
    }, [conversationId]);

    const applySnapshot = (payload, clientMessageId = null) => {
        if (payload?.status) {
            setStatus(payload.status);
            setMuted(payload.status === 'muted');
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

        const code = eventError(payload);
        if (code) {
            setError(code);
        }

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
            playbackRef.current = audio;
            audio.onended = () => {
                URL.revokeObjectURL(url);
                playbackRef.current = null;
            };
            audio.play().catch(() => {
                URL.revokeObjectURL(url);
            });
        } catch {
            setError('voice_runtime_failed');
        }
    };

    const stopPlayback = () => {
        const audio = playbackRef.current;
        if (audio) {
            audio.pause();
            audio.src = '';
            playbackRef.current = null;
        }
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
        setRecording(false);
    };

    const postJson = async (url, body = {}) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: jsonHeaders(),
            body: JSON.stringify(body),
        });
        const payload = await readJson(response);
        if (!response.ok) {
            throw new Error(payload.error || payload.message || 'voice_runtime_failed');
        }
        return payload;
    };

    const startVoice = async () => {
        if (busy || unsupported || !conversationId) {
            return;
        }

        setBusy(true);
        setError('');
        setTranscript('');
        setAssistantText('');

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            streamRef.current = stream;
            setPermission('granted');

            const created = await postJson(route('jarvis.voice.sessions.store', conversationId), {
                origin: 'web',
            });
            sessionIdRef.current = created.public_id;
            setSessionId(created.public_id);
            applySnapshot(created);

            const listening = await postJson(route('jarvis.voice.sessions.listen', created.public_id));
            applySnapshot(listening);
            beginRecording(stream);
        } catch (caught) {
            stopCapture();
            if (caught?.name === 'NotAllowedError' || caught?.name === 'NotFoundError') {
                setPermission('denied');
                setError('voice_microphone_unavailable');
            } else {
                setError(caught.message || 'voice_runtime_failed');
            }
            setStatus('error');
        } finally {
            setBusy(false);
        }
    };

    const beginRecording = (stream) => {
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
            if (blob.size > 0 && sessionIdRef.current && !endingRef.current) {
                sendUtterance(blob, recorder.mimeType);
            }
        };

        recorderRef.current = recorder;
        recordStartedAt.current = Date.now();
        recorder.start();
        setRecording(true);
    };

    const sendUtterance = async (blob, mimeType) => {
        const id = sessionIdRef.current;
        if (!id) {
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
            const response = await fetch(route('jarvis.voice.sessions.audio', id), {
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
            if (!response.ok) {
                throw new Error(payload.error || payload.message || 'voice_runtime_failed');
            }
            applySnapshot(payload, clientMessageId);
        } catch (caught) {
            setError(caught.message || 'voice_runtime_failed');
        } finally {
            setBusy(false);
            if (sessionIdRef.current && streamRef.current && !muted && !endingRef.current) {
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
        if (!sessionId) {
            await startVoice();
            return;
        }

        if (recording) {
            stopUtterance();
            return;
        }

        if (streamRef.current && !muted) {
            beginRecording(streamRef.current);
        }
    };

    const handleMute = async () => {
        const id = sessionIdRef.current;
        if (!id || busy) {
            return;
        }

        setBusy(true);
        try {
            if (muted) {
                const payload = await postJson(route('jarvis.voice.sessions.resume', id));
                applySnapshot(payload);
                if (!streamRef.current) {
                    streamRef.current = await navigator.mediaDevices.getUserMedia({ audio: true });
                    setPermission('granted');
                }
                const listening = await postJson(route('jarvis.voice.sessions.listen', id));
                applySnapshot(listening);
                beginRecording(streamRef.current);
            } else {
                skipSendRef.current = true;
                stopPlayback();
                stopCapture();
                const payload = await postJson(route('jarvis.voice.sessions.mute', id));
                applySnapshot(payload);
            }
        } catch (caught) {
            setError(caught.message || 'voice_runtime_failed');
        } finally {
            setBusy(false);
        }
    };

    const handleInterrupt = async () => {
        const id = sessionIdRef.current;
        if (!id) {
            return;
        }

        stopPlayback();
        try {
            const payload = await postJson(route('jarvis.voice.sessions.interrupt', id));
            applySnapshot(payload);
        } catch (caught) {
            setError(caught.message || 'voice_runtime_failed');
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
                await fetch(route('jarvis.voice.sessions.destroy', id), {
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
    };

    const handleText = async () => {
        await endSession();
        onSwitchToText?.();
    };

    const orbLabel = unsupported
        ? 'Browser not supported'
        : sessionId
            ? status
            : 'Voice';

    const hint = unsupported
        ? 'This browser cannot capture microphone audio. Use a recent Chrome, Edge, or Firefox, or stay in Text mode.'
        : error
            ? error
            : sessionId
                ? 'Same conversation as Text. Transcripts are ordinary messages.'
                : 'Start Voice requests the microphone. Switching to Text ends the voice session and keeps this chat.';

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex min-h-0 flex-1 flex-col items-center justify-center px-6">
                <OrbPlaceholder label={orbLabel} state={status} hint={hint} />
                <div className="mt-4 w-full max-w-lg space-y-2 text-center">
                    {transcript ? (
                        <p className="text-sm text-slate-200">{transcript}</p>
                    ) : (
                        <p className="text-sm text-slate-500">Current transcript appears here.</p>
                    )}
                    {assistantText ? (
                        <p className="text-sm text-sky-200">{assistantText}</p>
                    ) : null}
                    {error ? (
                        <p className="text-xs text-amber-300">{error}</p>
                    ) : null}
                    {permission === 'denied' ? (
                        <p className="text-xs text-amber-300">Microphone permission was denied.</p>
                    ) : null}
                </div>
            </div>

            <div className="border-t border-white/10 bg-black/20 px-4 py-4">
                <p className="mb-3 text-center text-xs text-slate-500">
                    Conversation {conversationId ? `#${conversationId}` : ''} stays selected.
                    {busy ? ' Working…' : ''}
                </p>
                <div className="mx-auto flex max-w-md items-center justify-center gap-3">
                    <button
                        type="button"
                        disabled={!sessionId || busy || unsupported}
                        onClick={handleMute}
                        className="inline-flex h-11 w-11 items-center justify-center rounded-full border border-white/10 text-slate-200 hover:bg-white/10 disabled:opacity-40"
                        aria-label={muted ? 'Resume microphone' : 'Mute microphone'}
                    >
                        {muted ? <MicOff className="h-4 w-4" /> : <Mic className="h-4 w-4 opacity-70" />}
                    </button>
                    <button
                        type="button"
                        disabled={busy || unsupported || !conversationId}
                        onClick={handleMic}
                        className={`inline-flex h-12 w-12 items-center justify-center rounded-full border ${
                            recording
                                ? 'border-sky-400 bg-sky-400/20 text-sky-100'
                                : 'border-white/10 bg-white/5 text-white hover:bg-white/10'
                        } disabled:opacity-40`}
                        aria-label={sessionId ? (recording ? 'Send utterance' : 'Start listening') : 'Start Voice'}
                    >
                        {busy ? <Loader2 className="h-5 w-5 animate-spin" /> : <Mic className="h-5 w-5" />}
                    </button>
                    <button
                        type="button"
                        disabled={!sessionId || busy}
                        onClick={handleEnd}
                        className="inline-flex h-11 w-11 items-center justify-center rounded-full border border-white/10 text-slate-200 hover:bg-white/10 disabled:opacity-40"
                        aria-label="End voice"
                    >
                        <PhoneOff className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        onClick={handleText}
                        className="inline-flex h-11 items-center gap-2 rounded-full border border-sky-400/30 bg-sky-400/10 px-4 text-sm font-medium text-sky-200 hover:bg-sky-400/20"
                        aria-label="Switch to text"
                    >
                        <Type className="h-4 w-4" />
                        Text
                    </button>
                </div>
                {status === 'speaking' ? (
                    <div className="mt-3 flex justify-center">
                        <button
                            type="button"
                            onClick={handleInterrupt}
                            className="text-xs text-slate-400 underline hover:text-white"
                        >
                            Interrupt playback
                        </button>
                    </div>
                ) : null}
            </div>
        </div>
    );
}
