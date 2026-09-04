export const VOICE_STATES = [
    'connecting',
    'idle',
    'listening',
    'transcribing',
    'thinking',
    'speaking',
    'interrupted',
    'muted',
    'error',
    'ended',
];

export function emptyFrequencyBands() {
    return {
        sub: 0,
        low: 0,
        lowMid: 0,
        mid: 0,
        highMid: 0,
        high: 0,
    };
}

/**
 * Canonical Orb contract. Renderer must not receive providers or runtime internals.
 *
 * @param {object} partial
 * @returns {object}
 */
export function createVoiceVisualizationState(partial = {}) {
    return {
        state: partial.state ?? 'idle',
        inputAmplitude: clamp01(partial.inputAmplitude ?? 0),
        outputAmplitude: clamp01(partial.outputAmplitude ?? 0),
        frequencyBands: {
            ...emptyFrequencyBands(),
            ...(partial.frequencyBands ?? {}),
        },
        connectionState: partial.connectionState ?? 'disconnected',
        transitionProgress: clamp01(partial.transitionProgress ?? 1),
        isMuted: Boolean(partial.isMuted),
        reducedMotion: Boolean(partial.reducedMotion),
    };
}

export function clamp01(value) {
    const n = Number(value);

    if (! Number.isFinite(n)) {
        return 0;
    }

    return Math.min(1, Math.max(0, n));
}
