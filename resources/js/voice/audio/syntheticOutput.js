import { emptyFrequencyBands } from '../visualization/VoiceVisualizationState';

/**
 * Deterministic visualization-only speaking energy. Not TTS.
 */
export function syntheticSpeaking(seconds) {
    const t = seconds;
    const envelope = 0.42 + 0.38 * (0.5 + 0.5 * Math.sin(t * 3.7)) * (0.55 + 0.45 * Math.sin(t * 1.6));
    const pulse = 0.12 * Math.max(0, Math.sin(t * 9.4));

    return {
        outputAmplitude: Math.min(1, envelope + pulse),
        frequencyBands: {
            sub: 0.35 + 0.25 * Math.sin(t * 1.9),
            low: 0.4 + 0.3 * Math.sin(t * 2.4 + 0.4),
            lowMid: 0.32 + 0.28 * Math.sin(t * 3.1 + 1.1),
            mid: 0.28 + 0.35 * Math.sin(t * 4.2 + 0.2),
            highMid: 0.18 + 0.4 * Math.sin(t * 6.1 + 2.0),
            high: 0.12 + 0.38 * Math.sin(t * 8.3 + 0.7),
        },
    };
}

export function quietBands() {
    return emptyFrequencyBands();
}
