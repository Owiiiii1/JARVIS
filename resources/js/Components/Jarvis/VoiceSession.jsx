import { Loader2, Mic, MicOff, PhoneOff, Settings2, Square, Type } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { VoiceAudioAnalyzer } from '@/voice/audio/VoiceAudioAnalyzer';
import { describeBlobMime, pickRecorderMime } from '@/voice/audio/mime';
import { prepareUtteranceBlob } from '@/voice/audio/transcodeToWav';
import { quietBands, syntheticSpeaking } from '@/voice/audio/syntheticOutput';
import {
    applyAudioOutput,
    canSelectAudioOutput,
    listAudioDevices,
    requestMicrophoneStream,
    resumeSharedAudioContext,
    setStreamEnabled,
    stopStream,
    takePendingMicrophoneStream,
} from '@/voice/audio/voiceMedia';
import { VoiceTurnDetector } from '@/voice/audio/VoiceTurnDetector';
import VoiceDemoDrawer from '@/voice/components/VoiceDemoDrawer';
import { isVoiceDemoEnabled } from '@/voice/demo';
import { prefersReducedMotion } from '@/voice/visualization/capabilities';
import JarvisVoiceOrb from '@/voice/visualization/JarvisVoiceOrb';
import { createVoiceVisualizationState } from '@/voice/visualization/VoiceVisualizationState';

const PROVIDER_CODES = new Set(['voice_stt_not_configured', 'voice_tts_not_configured']);
const RECOVERABLE_CODES = new Set([
    'voice_session_invalid_state',
    'voice_audio_format_unsupported',
    'voice_stt_rate_limited',
    'voice_stt_timeout',
    'voice_stt_failed',
    'voice_tts_failed',
    'voice_runtime_failed',
]);

const STATE_LABELS = {
    connecting: 'Connecting…',
    idle: 'Listening…',
    listening: 'Listening…',
    transcribing: 'Thinking…',
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
    if (code === 'voice_stt_rate_limited') {
        return 'Speech recognition is busy. Try again shortly.';
    }
    if (code === 'voice_stt_timeout') {
        return 'Speech recognition timed out.';
    }
    if (code === 'voice_tts_failed') {
        return 'Spoken reply could not be played.';
    }

    return code && ! RECOVERABLE_CODES.has(code) ? code : (code ? friendlyRecoverable(code) : '');
}

function friendlyRecoverable(code) {
    if (code === 'voice_session_invalid_state') {
        return 'Voice state caught up. Listening again.';
    }
    if (code === 'voice_stt_failed' || code === 'voice_runtime_failed') {
        return 'That turn did not go through. Listening again.';
    }

    return '';
}

function canInterruptStatus(status) {
    return status === 'speaking' || status === 'thinking';
}

const STORAGE_KEYS = {
    mic: 'jarvis.voice.micDeviceId',
    speaker: 'jarvis.voice.speakerDeviceId',
};

function readStorage(key, fallback) {
    try {
        return window.localStorage.getItem(key) || fallback;
    } catch {
        return fallback;
    }
}

function writeStorage(key, value) {
    try {
        if (value) {
            window.localStorage.setItem(key, value);
        } else {
            window.localStorage.removeItem(key);
        }
    } catch {
        // ignore
    }
}

function deviceLabel(device, index, kind) {
    if (device?.label) {
        return device.label;
    }

    return `${kind} ${index + 1}`;
}

/**
 * Push-to-talk Workspace Voice client.
 */
export default function VoiceSession({
    conversationId,
    onSwitchToText,
    onTurn,
    surface = 'jarvis',
    voiceClient = {},
}) {
    const demoEnabled = isVoiceDemoEnabled();
    const [status, setStatus] = useState('connecting');
    const [demoState, setDemoState] = useState(null);
    const [sessionId, setSessionId] = useState(null);
    const [busy, setBusy] = useState(false);
    const [muted, setMuted] = useState(false);
    const [unsupported, setUnsupported] = useState(false);
    const [error, setError] = useState('');
    const [providerNotice, setProviderNotice] = useState('');
    const [transcript, setTranscript] = useState('');
    const [assistantText, setAssistantText] = useState('');
    const [micCta, setMicCta] = useState(false);
    const [audioCta, setAudioCta] = useState(false);
    const [pttHolding, setPttHolding] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [micId, setMicId] = useState(() => readStorage(STORAGE_KEYS.mic, ''));
    const [speakerId, setSpeakerId] = useState(() => readStorage(STORAGE_KEYS.speaker, ''));
    const [audioInputs, setAudioInputs] = useState([]);
    const [audioOutputs, setAudioOutputs] = useState([]);

    const sessionIdRef = useRef(null);
    const recorderRef = useRef(null);
    const streamRef = useRef(null);
    const chunksRef = useRef([]);
    const playbackRef = useRef(null);
    const pendingAudioRef = useRef(null);
    const sequenceRef = useRef(0);
    const recordStartedAt = useRef(null);
    const mimeRef = useRef(pickRecorderMime(voiceClient.recorder_mime_candidates));
    const endingRef = useRef(false);
    const skipSendRef = useRef(false);
    const sendingRef = useRef(false);
    const capturingRef = useRef(false);
    const visualOnlyRef = useRef(false);
    const analyserRef = useRef(null);
    const vadRef = useRef(null);
    const statusRef = useRef('connecting');
    const demoStateRef = useRef(null);
    const mutedRef = useRef(false);
    const sessionPresentRef = useRef(false);
    const opRef = useRef(null);
    const genRef = useRef(0);
    const pttHoldingRef = useRef(false);
    const micIdRef = useRef(micId);
    const speakerIdRef = useRef(speakerId);
    const maxUtteranceMs = Math.max(5, Number(voiceClient.max_utterance_seconds || 30)) * 1000;
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
        micIdRef.current = micId;
    }, [micId]);
    useEffect(() => {
        speakerIdRef.current = speakerId;
    }, [speakerId]);
    useEffect(() => {
        if (! settingsOpen || ! navigator.mediaDevices?.addEventListener) {
            return undefined;
        }

        const onChange = () => {
            refreshDevices();
        };
        navigator.mediaDevices.addEventListener('devicechange', onChange);

        return () => navigator.mediaDevices.removeEventListener('devicechange', onChange);
        // refreshDevices is a render-local helper.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [settingsOpen]);

    useEffect(() => {
        setUnsupported(! browserSupported());
        mimeRef.current = pickRecorderMime(voiceClient.recorder_mime_candidates);
        analyserRef.current = new VoiceAudioAnalyzer();
        vadRef.current = new VoiceTurnDetector({ maxUtteranceMs });
        endingRef.current = false;
        const gen = ++genRef.current;

        let raf = 0;
        const tick = (now) => {
            const analysis = analyserRef.current?.tick() ?? {
                inputAmplitude: 0,
                rawInputRms: 0,
                outputAmplitude: 0,
                frequencyBands: quietBands(),
                outputBands: quietBands(),
            };
            const visualState = demoStateRef.current ?? statusRef.current;
            const thinking = visualState === 'thinking' || visualState === 'transcribing';
            const speaking = visualState === 'speaking';
            const listening = visualState === 'listening' || visualState === 'idle' || visualState === 'connecting';
            let inputAmplitude = listening && ! thinking && ! mutedRef.current ? analysis.inputAmplitude : 0;
            let outputAmplitude = speaking ? analysis.outputAmplitude : 0;
            let frequencyBands = listening && ! mutedRef.current
                ? analysis.frequencyBands
                : speaking ? analysis.outputBands : quietBands();

            if (speaking && outputAmplitude < 0.05) {
                const syn = syntheticSpeaking(now / 1000);
                outputAmplitude = syn.outputAmplitude;
                frequencyBands = syn.frequencyBands;
            }

            if (thinking || mutedRef.current) {
                if (mutedRef.current) {
                    inputAmplitude = 0;
                    outputAmplitude = 0;
                    frequencyBands = quietBands();
                } else if (thinking) {
                    inputAmplitude = 0;
                    outputAmplitude = 0;
                    frequencyBands = quietBands();
                }
            }

            vizRef.current = createVoiceVisualizationState({
                state: mutedRef.current ? 'muted' : visualState,
                inputAmplitude,
                outputAmplitude,
                frequencyBands,
                connectionState: connectionFrom(statusRef.current, sessionPresentRef.current || demoEnabled),
                isMuted: mutedRef.current || visualState === 'muted',
                reducedMotion: prefersReducedMotion(),
            });

            if (capturingRef.current && recordStartedAt.current && now - recordStartedAt.current >= maxUtteranceMs) {
                if (vadRef.current?.speechDetected) {
                    finalizeCapture('max_utterance');
                } else {
                    recycleCapture();
                }
            }

            raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);

        if (browserSupported() && conversationId) {
            startVoice(gen);
        } else if (! browserSupported()) {
            setStatus('error');
        } else {
            setMicCta(true);
        }

        return () => {
            genRef.current += 1;
            cancelAnimationFrame(raf);
            endingRef.current = true;
            skipSendRef.current = true;
            stopPlayback();
            stopRecorder(true);
            stopStream(streamRef.current);
            streamRef.current = null;
            analyserRef.current?.dispose();
            analyserRef.current = null;
            const id = sessionIdRef.current;
            sessionIdRef.current = null;
            if (id) {
                fetch(route(`${surface}.voice.sessions.destroy`, id), {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: jsonHeaders(),
                    keepalive: true,
                }).catch(() => {});
            }
        };
        // Conversation switch ends the previous session in cleanup and starts a new one.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [conversationId, demoEnabled, surface]);

    const applySnapshot = (payload, clientMessageId = null) => {
        const code = eventError(payload);

        if (code && PROVIDER_CODES.has(code)) {
            if (code === 'voice_stt_not_configured') {
                visualOnlyRef.current = true;
                skipSendRef.current = true;
            }
            setProviderNotice('Speech providers not configured.');
        } else if (code && RECOVERABLE_CODES.has(code)) {
            setError(friendlyError(code));
        } else if (code) {
            setError(friendlyError(code));
        }

        if (payload?.status && payload.status !== 'error') {
            setStatus(payload.status);
            setMuted(payload.status === 'muted');
        } else if (payload?.status === 'error' && ! (code && PROVIDER_CODES.has(code))) {
            if (code && RECOVERABLE_CODES.has(code)) {
                setStatus('listening');
            } else {
                setStatus('error');
            }
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

        return payload?.status ?? statusRef.current;
    };

    const playAudio = (base64, mime) => {
        stopPlayback();
        pendingAudioRef.current = { base64, mime };
        try {
            const bytes = Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
            const blob = new Blob([bytes], { type: mime || 'audio/mpeg' });
            const url = URL.createObjectURL(blob);
            const audio = new Audio(url);
            audio.crossOrigin = 'anonymous';
            playbackRef.current = audio;
            analyserRef.current?.connectOutputAudio(audio);
            applyAudioOutput(audio, speakerIdRef.current);
            audio.onended = () => {
                URL.revokeObjectURL(url);
                playbackRef.current = null;
                pendingAudioRef.current = null;
                afterPlayback();
            };
            resumeSharedAudioContext();
            audio.play().then(() => {
                setAudioCta(false);
            }).catch(() => {
                URL.revokeObjectURL(url);
                playbackRef.current = null;
                setAudioCta(true);
            });
        } catch {
            setError(friendlyError('voice_tts_failed'));
            afterPlayback();
        }
    };

    const afterPlayback = async () => {
        if (endingRef.current || mutedRef.current) {
            return;
        }

        try {
            await ensureListening();
        } catch (caught) {
            await recoverFrom(caught);
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

    const stopRecorder = (discard) => {
        const recorder = recorderRef.current;
        if (discard) {
            skipSendRef.current = true;
        }
        capturingRef.current = false;
        if (recorder && recorder.state !== 'inactive') {
            try {
                recorder.stop();
            } catch {
                // ignore
            }
        }
        recorderRef.current = null;
        recordStartedAt.current = null;
    };

    const refreshDevices = async () => {
        try {
            const { inputs, outputs } = await listAudioDevices();
            setAudioInputs(inputs);
            setAudioOutputs(outputs);
        } catch {
            // ignore
        }
    };

    const acquireStream = async () => {
        const wanted = micIdRef.current || null;
        const pending = takePendingMicrophoneStream();
        if (pending) {
            const currentId = pending.getAudioTracks()[0]?.getSettings?.()?.deviceId;
            if (! wanted || currentId === wanted) {
                streamRef.current = pending;
                setStreamEnabled(pending, true);
                analyserRef.current?.connectInputStream(pending);
                setMicCta(false);
                refreshDevices();

                return pending;
            }

            stopStream(pending);
        }

        if (streamRef.current && streamRef.current.getAudioTracks().some((track) => track.readyState === 'live')) {
            const currentId = streamRef.current.getAudioTracks()[0]?.getSettings?.()?.deviceId;
            if (! wanted || currentId === wanted) {
                setStreamEnabled(streamRef.current, true);
                analyserRef.current?.connectInputStream(streamRef.current);
                refreshDevices();

                return streamRef.current;
            }

            stopStream(streamRef.current);
            streamRef.current = null;
        }

        const stream = await requestMicrophoneStream(wanted);
        streamRef.current = stream;
        analyserRef.current?.connectInputStream(stream);
        setMicCta(false);
        refreshDevices();

        return stream;
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
            const err = new Error(payload.error || payload.message || 'voice_runtime_failed');
            err.code = payload.error || payload.message;
            throw err;
        }

        return payload;
    };

    const getSnapshot = async (id) => {
        const response = await fetch(route(`${surface}.voice.sessions.show`, id), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const payload = await readJson(response);
        if (! response.ok) {
            const err = new Error(payload.error || 'voice_runtime_failed');
            err.code = payload.error;
            throw err;
        }

        return payload;
    };

    const withLock = async (name, fn) => {
        if (opRef.current === name || (opRef.current && name !== 'recover')) {
            return null;
        }
        opRef.current = name;
        try {
            return await fn();
        } finally {
            if (opRef.current === name) {
                opRef.current = null;
            }
        }
    };

    const ensureListening = async () => {
        const id = sessionIdRef.current;
        if (! id || mutedRef.current || endingRef.current) {
            return null;
        }
        if (statusRef.current === 'listening') {
            return null;
        }

        const payload = await postJson(route(`${surface}.voice.sessions.listen`, id));

        return applySnapshot(payload);
    };

    const startCapture = ({ forceSpeech = false } = {}) => {
        if (endingRef.current || mutedRef.current || sendingRef.current) {
            return;
        }
        if (statusRef.current === 'speaking' || statusRef.current === 'transcribing' || statusRef.current === 'thinking') {
            return;
        }

        const stream = streamRef.current;
        if (! stream) {
            return;
        }

        stopRecorder(true);
        skipSendRef.current = false;
        vadRef.current.reset(performance.now());
        if (forceSpeech) {
            vadRef.current.enterSpeech(performance.now());
        }
        setStreamEnabled(stream, true);
        analyserRef.current?.connectInputStream(stream);

        if (visualOnlyRef.current) {
            capturingRef.current = true;

            return;
        }

        const chosen = mimeRef.current;
        const options = chosen?.raw ? { mimeType: chosen.raw } : {};
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
            const blob = new Blob(chunksRef.current, { type: recorder.mimeType || chosen?.raw || 'audio/webm' });
            chunksRef.current = [];
            capturingRef.current = false;
            if (skipSendRef.current) {
                skipSendRef.current = false;

                return;
            }
            if (blob.size > 0 && vadRef.current.speechDetected && sessionIdRef.current && ! endingRef.current && ! visualOnlyRef.current) {
                sendUtterance(blob, recorder.mimeType || chosen?.raw);
            }
        };

        recorderRef.current = recorder;
        recordStartedAt.current = performance.now();
        capturingRef.current = true;
        try {
            recorder.start(100);
        } catch {
            recorder.start();
        }
    };

    const finalizeCapture = (reason) => {
        if (! capturingRef.current || sendingRef.current) {
            return;
        }
        if (reason !== 'max_utterance' && ! vadRef.current.speechDetected) {
            recycleCapture();

            return;
        }
        skipSendRef.current = false;
        const recorder = recorderRef.current;
        if (recorder && recorder.state !== 'inactive') {
            try {
                recorder.stop();
            } catch {
                // ignore
            }
        }
        recorderRef.current = null;
        capturingRef.current = false;
    };

    const recycleCapture = () => {
        skipSendRef.current = true;
        stopRecorder(true);
        if (endingRef.current || mutedRef.current || statusRef.current !== 'listening') {
            return;
        }

        if (pttHoldingRef.current) {
            startCapture({ forceSpeech: true });
        }
    };

    const sendUtterance = async (blob, rawMime) => {
        const id = sessionIdRef.current;
        if (! id || visualOnlyRef.current || sendingRef.current || mutedRef.current) {
            return;
        }

        sendingRef.current = true;
        setBusy(true);
        sequenceRef.current += 1;
        const clientMessageId = newClientId();
        const durationMs = recordStartedAt.current ? Math.round(performance.now() - recordStartedAt.current) : null;
        let parsed;
        let audioBlob = blob;
        try {
            const prepared = await prepareUtteranceBlob(blob, rawMime || mimeRef.current?.raw, voiceClient.stt_provider);
            audioBlob = prepared.blob;
            parsed = describeBlobMime(prepared.blob, prepared.rawMime);
        } catch {
            sendingRef.current = false;
            sequenceRef.current -= 1;
            setBusy(false);
            setError(friendlyError('voice_audio_format_unsupported'));

            return;
        }

        const form = new FormData();
        form.append('audio', audioBlob, parsed.filename);
        form.append('sequence', String(sequenceRef.current));
        form.append('is_final', '1');
        form.append('client_message_id', clientMessageId);
        form.append('channels', '1');
        form.append('mime', parsed.canonical);
        form.append('raw_mime', parsed.raw);
        if (durationMs) {
            form.append('duration_ms', String(durationMs));
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
                const err = new Error(payload.error || payload.message || 'voice_runtime_failed');
                err.code = payload.error || payload.message;
                throw err;
            }
            applySnapshot(payload, clientMessageId);
        } catch (caught) {
            await recoverFrom(caught);
        } finally {
            sendingRef.current = false;
            setBusy(false);
        }
    };

    const recoverFrom = async (caught) => {
        opRef.current = null;
        const code = caught?.code || caught?.message;
        if (code && PROVIDER_CODES.has(code)) {
            visualOnlyRef.current = true;
            skipSendRef.current = true;
            setProviderNotice('Speech providers not configured.');
            setStatus('listening');

            return;
        }

        setError(friendlyError(code || 'voice_runtime_failed'));

        if (code === 'voice_session_invalid_state' && sessionIdRef.current) {
            try {
                const snapshot = await getSnapshot(sessionIdRef.current);
                const next = applySnapshot(snapshot);
                if (next === 'idle' && ! mutedRef.current) {
                    await ensureListening();
                } else if (next === 'ended' || next === 'error') {
                    await restartSession();
                }
            } catch {
                await restartSession();
            }

            return;
        }

        if (RECOVERABLE_CODES.has(code) && sessionIdRef.current && ! mutedRef.current && ! endingRef.current) {
            try {
                await ensureListening();
            } catch {
                await restartSession();
            }
        }
    };

    const restartSession = async () => {
        skipSendRef.current = true;
        stopRecorder(true);
        const id = sessionIdRef.current;
        sessionIdRef.current = null;
        setSessionId(null);
        if (id) {
            fetch(route(`${surface}.voice.sessions.destroy`, id), {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: jsonHeaders(),
            }).catch(() => {});
        }
        if (! endingRef.current && conversationId) {
            await startVoice(genRef.current);
        }
    };

    const startVoice = async (gen) => {
        if (unsupported || ! conversationId) {
            return;
        }

        return withLock('start', async () => {
            if (gen !== genRef.current || sessionIdRef.current) {
                return;
            }

            setBusy(true);
            setError('');
            setProviderNotice('');
            setTranscript('');
            setAssistantText('');
            setStatus('connecting');
            visualOnlyRef.current = false;

            try {
                await resumeSharedAudioContext();
                await acquireStream();

                const created = await postJson(route(`${surface}.voice.sessions.store`, conversationId), {
                    origin: 'web',
                });
                if (gen !== genRef.current) {
                    return;
                }
                sessionIdRef.current = created.public_id;
                setSessionId(created.public_id);
                applySnapshot(created);

                const listening = await postJson(route(`${surface}.voice.sessions.listen`, created.public_id));
                if (gen !== genRef.current) {
                    return;
                }
                applySnapshot(listening);
            } catch (caught) {
                if (caught?.name === 'NotAllowedError' || caught?.name === 'NotFoundError' || caught?.message === 'voice_microphone_unavailable') {
                    setMicCta(true);
                    setError(friendlyError('voice_microphone_unavailable'));
                    setStatus('idle');
                } else if (PROVIDER_CODES.has(caught.message) || PROVIDER_CODES.has(caught.code)) {
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
        });
    };

    const handleMute = async () => {
        const id = sessionIdRef.current;
        if (demoEnabled && ! id) {
            setMuted((current) => ! current);
            setDemoState((current) => (current === 'muted' ? 'listening' : 'muted'));
            setStreamEnabled(streamRef.current, muted);

            return;
        }

        if (! id) {
            return;
        }

        await withLock('mute', async () => {
            setBusy(true);
            try {
                if (mutedRef.current) {
                    await resumeSharedAudioContext();
                    await acquireStream();
                    const resumed = await postJson(route(`${surface}.voice.sessions.resume`, id));
                    applySnapshot(resumed);
                    if ((resumed.status || statusRef.current) === 'idle') {
                        const listening = await postJson(route(`${surface}.voice.sessions.listen`, id));
                        applySnapshot(listening);
                    }
                } else {
                    skipSendRef.current = true;
                    stopPlayback();
                    stopRecorder(true);
                    setStreamEnabled(streamRef.current, false);
                    const payload = await postJson(route(`${surface}.voice.sessions.mute`, id));
                    applySnapshot(payload);
                }
            } catch (caught) {
                await recoverFrom(caught);
            } finally {
                setBusy(false);
            }
        });
    };

    const handleInterrupt = async () => {
        if (demoEnabled && demoState) {
            setDemoState('listening');
            stopPlayback();

            return;
        }

        const id = sessionIdRef.current;
        if (! id || ! canInterruptStatus(statusRef.current)) {
            return;
        }

        await withLock('interrupt', async () => {
            stopPlayback();
            try {
                const payload = await postJson(route(`${surface}.voice.sessions.interrupt`, id));
                applySnapshot(payload);
                await ensureListening();
            } catch (caught) {
                setError(friendlyError(caught.message || 'voice_runtime_failed'));
            }
        });
    };

    const handlePttDown = async (event) => {
        event.preventDefault();
        if (mutedRef.current || endingRef.current || sendingRef.current || unsupported) {
            return;
        }
        if (! sessionIdRef.current && ! demoEnabled) {
            return;
        }

        try {
            event.currentTarget.setPointerCapture(event.pointerId);
        } catch {
            // ignore
        }

        pttHoldingRef.current = true;
        setPttHolding(true);

        if (canInterruptStatus(statusRef.current)) {
            await handleInterrupt();
        }

        if (! pttHoldingRef.current) {
            return;
        }

        startCapture({ forceSpeech: true });
    };

    const handlePttUp = (event) => {
        if (event?.currentTarget && event.pointerId != null) {
            try {
                event.currentTarget.releasePointerCapture(event.pointerId);
            } catch {
                // ignore
            }
        }

        if (! pttHoldingRef.current) {
            return;
        }

        pttHoldingRef.current = false;
        setPttHolding(false);
        finalizeCapture('ptt_release');
    };

    const applyMicDevice = async (nextId) => {
        micIdRef.current = nextId;
        setMicId(nextId);
        writeStorage(STORAGE_KEYS.mic, nextId);
        const holding = pttHoldingRef.current;
        stopRecorder(true);
        stopStream(streamRef.current);
        streamRef.current = null;

        try {
            await acquireStream();
            if (holding) {
                startCapture({ forceSpeech: true });
            }
        } catch {
            setMicCta(true);
            setError(friendlyError('voice_microphone_unavailable'));
        }
    };

    const applySpeakerDevice = async (nextId) => {
        speakerIdRef.current = nextId;
        setSpeakerId(nextId);
        writeStorage(STORAGE_KEYS.speaker, nextId);
        await applyAudioOutput(playbackRef.current, nextId);
    };

    const handleOpenSettings = async () => {
        const next = ! settingsOpen;
        setSettingsOpen(next);
        if (next) {
            await refreshDevices();
        }
    };

    const endSession = async () => {
        endingRef.current = true;
        skipSendRef.current = true;
        stopPlayback();
        stopRecorder(true);
        stopStream(streamRef.current);
        streamRef.current = null;
        const id = sessionIdRef.current;
        sessionIdRef.current = null;
        setSessionId(null);
        setStatus('ended');
        setMuted(false);
        pttHoldingRef.current = false;
        setPttHolding(false);

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
        await withLock('end', async () => {
            await endSession();
            endingRef.current = false;
            setStatus('idle');
            setDemoState(null);
        });
    };

    const handleText = async () => {
        await endSession();
        onSwitchToText?.();
    };

    const handleEnableMic = async () => {
        try {
            await resumeSharedAudioContext();
            await acquireStream();
            setMicCta(false);
            await startVoice(genRef.current);
        } catch {
            setMicCta(true);
            setError(friendlyError('voice_microphone_unavailable'));
        }
    };

    const handleEnableAudio = async () => {
        await resumeSharedAudioContext();
        const pending = pendingAudioRef.current;
        if (pending) {
            playAudio(pending.base64, pending.mime);
        } else {
            setAudioCta(false);
            afterPlayback();
        }
    };

    const handleDemoMic = async () => {
        try {
            await acquireStream();
            setDemoState((current) => current ?? 'listening');
        } catch {
            setMicCta(true);
            setError(friendlyError('voice_microphone_unavailable'));
        }
    };

    const listeningLike = orbState === 'listening' || orbState === 'idle';
    const stateLabel = unsupported
        ? 'Browser not supported'
        : listeningLike && ! muted
            ? (pttHolding ? 'Говорите…' : 'Рация — зажмите кнопку')
            : STATE_LABELS[orbState] ?? 'Voice';

    const notice = unsupported
        ? 'This browser cannot capture microphone audio. Use a recent Chrome, Edge, or Firefox, or stay in Text mode.'
        : providerNotice
            ? providerNotice
            : error
                ? error
                : '';

    const interruptible = canInterruptStatus(orbState);

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
                        <p className="jarvis-voice-mode__placeholder">
                            {pttHolding ? 'Запись…' : 'Зажмите кнопку, чтобы говорить'}
                        </p>
                    )}
                    {assistantText ? (
                        <p className="jarvis-voice-mode__assistant">{assistantText}</p>
                    ) : null}
                    {notice ? (
                        <p className="jarvis-voice-mode__notice">{notice}</p>
                    ) : null}
                    {micCta ? (
                        <button type="button" className="jarvis-voice-btn jarvis-voice-btn--text mt-3" onClick={handleEnableMic}>
                            Enable microphone
                        </button>
                    ) : null}
                    {audioCta ? (
                        <button type="button" className="jarvis-voice-btn jarvis-voice-btn--text mt-3" onClick={handleEnableAudio}>
                            Enable audio
                        </button>
                    ) : null}
                </div>
            </div>

            <div className="jarvis-voice-mode__bar">
                <p className="jarvis-voice-mode__meta">
                    {busy ? 'Working…' : ''}
                </p>
                <div className="jarvis-voice-mode__controls">
                    <button
                        type="button"
                        disabled={(! sessionId && ! demoEnabled) || busy || unsupported}
                        onClick={handleMute}
                        className={`jarvis-voice-btn jarvis-voice-btn--primary ${muted ? '' : 'is-live'}`}
                        aria-label={muted ? 'Unmute microphone' : 'Mute microphone'}
                    >
                        {busy && ! sessionId ? <Loader2 className="h-5 w-5 animate-spin" /> : (muted ? <MicOff className="h-5 w-5" /> : <Mic className="h-5 w-5" />)}
                    </button>
                    <button
                        type="button"
                        disabled={! interruptible || busy}
                        onClick={() => handleInterrupt()}
                        className="jarvis-voice-btn"
                        aria-label="Interrupt playback"
                    >
                        <Square className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        className={`jarvis-voice-btn ${settingsOpen ? 'is-active' : ''}`}
                        onClick={handleOpenSettings}
                        aria-label="Настройки микрофона и динамика"
                        aria-pressed={settingsOpen}
                    >
                        <Settings2 className="h-4 w-4" />
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
                <div className="flex justify-center">
                    <button
                        type="button"
                        disabled={(! sessionId && ! demoEnabled) || busy || unsupported || muted}
                        className={`jarvis-voice-btn jarvis-voice-btn--ptt ${pttHolding ? 'is-hold' : ''}`}
                        onPointerDown={handlePttDown}
                        onPointerUp={handlePttUp}
                        onPointerCancel={handlePttUp}
                        onLostPointerCapture={handlePttUp}
                        onContextMenu={(event) => event.preventDefault()}
                        aria-label="Push to talk"
                    >
                        {pttHolding ? 'Говорю… отпустите' : 'Держите, чтобы говорить'}
                    </button>
                </div>
            </div>

            {settingsOpen ? (
                <div className="jarvis-voice-settings">
                    <p className="jarvis-voice-settings__title">Устройства</p>
                    <label>
                        Микрофон
                        <select
                            value={micId}
                            onChange={(event) => applyMicDevice(event.target.value)}
                        >
                            <option value="">По умолчанию</option>
                            {audioInputs.map((device, index) => (
                                <option key={device.deviceId} value={device.deviceId}>
                                    {deviceLabel(device, index, 'Микрофон')}
                                </option>
                            ))}
                        </select>
                    </label>
                    {canSelectAudioOutput() ? (
                        <label>
                            Динамик
                            <select
                                value={speakerId}
                                onChange={(event) => applySpeakerDevice(event.target.value)}
                            >
                                <option value="">По умолчанию</option>
                                {audioOutputs.map((device, index) => (
                                    <option key={device.deviceId} value={device.deviceId}>
                                        {deviceLabel(device, index, 'Динамик')}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ) : (
                        <p className="jarvis-voice-settings__hint">
                            Выбор динамика недоступен в этом браузере. Используйте системный вывод.
                        </p>
                    )}
                </div>
            ) : null}

            <VoiceDemoDrawer
                enabled={demoEnabled}
                state={orbState}
                onState={setDemoState}
                onStartMic={handleDemoMic}
            />
        </div>
    );
}
