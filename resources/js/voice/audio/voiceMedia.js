let pendingStream = null;
let sharedContext = null;

export const MIC_CONSTRAINTS = {
    audio: {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true,
    },
};

export function getSharedAudioContext() {
    if (typeof window === 'undefined') {
        return null;
    }

    if (sharedContext && sharedContext.state !== 'closed') {
        return sharedContext;
    }

    const Ctor = window.AudioContext || window.webkitAudioContext;
    if (! Ctor) {
        return null;
    }

    sharedContext = new Ctor();

    return sharedContext;
}

export function resumeSharedAudioContext() {
    const ctx = getSharedAudioContext();
    if (ctx && ctx.state === 'suspended') {
        return ctx.resume().catch(() => {});
    }

    return Promise.resolve();
}

export async function requestMicrophoneStream() {
    if (! navigator.mediaDevices?.getUserMedia) {
        throw new Error('voice_microphone_unavailable');
    }

    try {
        return await navigator.mediaDevices.getUserMedia(MIC_CONSTRAINTS);
    } catch (first) {
        if (first?.name === 'OverconstrainedError' || first?.name === 'ConstraintNotSatisfiedError') {
            return navigator.mediaDevices.getUserMedia({ audio: true });
        }

        throw first;
    }
}

export async function primeVoiceMediaFromUserGesture() {
    await resumeSharedAudioContext();

    if (pendingStream && pendingStream.getTracks().some((track) => track.readyState === 'live')) {
        return pendingStream;
    }

    try {
        pendingStream = await requestMicrophoneStream();
    } catch {
        pendingStream = null;
    }

    return pendingStream;
}

export function takePendingMicrophoneStream() {
    const stream = pendingStream;
    pendingStream = null;

    return stream;
}

export function stopStream(stream) {
    stream?.getTracks()?.forEach((track) => {
        try {
            track.stop();
        } catch {
            // ignore
        }
    });
}

export function setStreamEnabled(stream, enabled) {
    stream?.getAudioTracks()?.forEach((track) => {
        track.enabled = enabled;
    });
}
