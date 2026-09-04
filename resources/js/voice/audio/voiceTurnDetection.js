/**
 * Local VAD / turn-taking defaults. Tune here; no Admin UI in M24.1.
 */
export const VOICE_TURN_DETECTION = {
    speechThresholdMin: 0.048,
    speechThresholdMax: 0.2,
    noiseMultiplier: 3.4,
    noiseAdaptUp: 0.08,
    noiseAdaptDown: 0.02,
    speechOnsetMs: 200,
    endSilenceMs: 850,
    minSpeechMs: 300,
    maxWaitingSegmentMs: 14000,
    bargeInThresholdMin: 0.13,
    bargeInOnsetMs: 280,
    postTtsGuardMs: 480,
};

export function speechThreshold(noiseFloor, config = VOICE_TURN_DETECTION) {
    const raw = noiseFloor * config.noiseMultiplier;

    return Math.min(config.speechThresholdMax, Math.max(config.speechThresholdMin, raw));
}
