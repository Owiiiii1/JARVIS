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

function audioConstraints(deviceId = null) {
    const audio = { ...MIC_CONSTRAINTS.audio };

    if (deviceId) {
        audio.deviceId = { exact: deviceId };
    }

    return { audio };
}

export async function requestMicrophoneStream(deviceId = null) {
    if (! navigator.mediaDevices?.getUserMedia) {
        throw new Error('voice_microphone_unavailable');
    }

    try {
        return await navigator.mediaDevices.getUserMedia(audioConstraints(deviceId));
    } catch (first) {
        if (deviceId) {
            try {
                return await navigator.mediaDevices.getUserMedia({
                    audio: {
                        ...MIC_CONSTRAINTS.audio,
                        deviceId: { ideal: deviceId },
                    },
                });
            } catch {
                // Fall through to unconstrained audio.
            }
        }

        if (first?.name === 'OverconstrainedError' || first?.name === 'ConstraintNotSatisfiedError') {
            return navigator.mediaDevices.getUserMedia({ audio: true });
        }

        throw first;
    }
}

export async function listAudioDevices() {
    if (! navigator.mediaDevices?.enumerateDevices) {
        return { inputs: [], outputs: [] };
    }

    const devices = await navigator.mediaDevices.enumerateDevices();

    return {
        inputs: devices.filter((device) => device.kind === 'audioinput' && device.deviceId),
        outputs: devices.filter((device) => device.kind === 'audiooutput' && device.deviceId),
    };
}

export function canSelectAudioOutput() {
    return typeof HTMLMediaElement !== 'undefined'
        && typeof HTMLMediaElement.prototype.setSinkId === 'function';
}

export async function applyAudioOutput(element, deviceId) {
    if (! element || typeof element.setSinkId !== 'function') {
        return false;
    }

    try {
        await element.setSinkId(deviceId || '');

        return true;
    } catch {
        return false;
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
