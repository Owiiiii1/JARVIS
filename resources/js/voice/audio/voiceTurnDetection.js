/**
 * Local VAD / turn-taking defaults. Tune here; no Admin UI.
 *
 * RMS scale is unamplified analyser energy in 0..1
 * (sqrt of mean squared byte-time-domain samples). Orb visualization
 * uses a separate visual gain and must not feed this detector.
 */
export const VOICE_TURN_DETECTION = {
    noiseCalibrationMs: 650,
    minCalibrationSamples: 10,
    startThresholdMin: 0.018,
    endThresholdMin: 0.011,
    startThresholdMax: 0.32,
    startNoiseMultiplier: 2.15,
    endNoiseMultiplier: 1.38,
    endToStartRatio: 0.75,
    speechOnsetMs: 200,
    endSilenceMs: 850,
    minSpeechMs: 300,
    middleHoldMs: 180,
    maxWaitingSegmentMs: 14000,
    maxUtteranceMs: 30000,
    noiseAdaptUp: 0.05,
    noiseAdaptDown: 0.04,
    candidateNoiseAdaptUp: 0.02,
    speechNoiseAdapt: 0.003,
    earlySpeechMultiplier: 3.0,
    bargeInThresholdMin: 0.055,
    bargeInMultiplier: 1.55,
    bargeInOnsetMs: 280,
    postTtsGuardMs: 480,
    debugThrottleMs: 250,
};

export const VISUAL_RMS_GAIN = 3.2;

export function startThreshold(noiseFloor, config = VOICE_TURN_DETECTION) {
    const raw = noiseFloor * config.startNoiseMultiplier;

    return Math.min(config.startThresholdMax, Math.max(config.startThresholdMin, raw));
}

export function endThreshold(noiseFloor, config = VOICE_TURN_DETECTION) {
    const start = startThreshold(noiseFloor, config);
    const raw = Math.max(config.endThresholdMin, noiseFloor * config.endNoiseMultiplier);

    return Math.min(raw, start * config.endToStartRatio);
}

export function bargeInThreshold(noiseFloor, config = VOICE_TURN_DETECTION) {
    const start = startThreshold(noiseFloor, config);

    return Math.max(config.bargeInThresholdMin, start * config.bargeInMultiplier);
}

export function percentile(values, p) {
    if (! Array.isArray(values) || values.length === 0) {
        return 0;
    }

    const sorted = values.slice().sort((a, b) => a - b);
    const idx = Math.min(sorted.length - 1, Math.max(0, Math.floor((sorted.length - 1) * p)));

    return sorted[idx];
}

export function isVoiceDebugEnabled() {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        return new URLSearchParams(window.location.search).get('voice_debug') === '1';
    } catch {
        return false;
    }
}

let lastVadDebugAt = 0;

export function maybeLogVoiceVad(diagnostics) {
    if (! isVoiceDebugEnabled()) {
        return;
    }

    const now = typeof performance !== 'undefined' ? performance.now() : Date.now();

    if (now - lastVadDebugAt < VOICE_TURN_DETECTION.debugThrottleMs && ! diagnostics?.event) {
        return;
    }

    lastVadDebugAt = now;
    console.debug('[voice_vad]', {
        rms: round4(diagnostics.rms),
        noiseFloor: round4(diagnostics.noiseFloor),
        startThreshold: round4(diagnostics.startThreshold),
        endThreshold: round4(diagnostics.endThreshold),
        phase: diagnostics.phase,
        speechDetected: diagnostics.speechDetected,
        silenceMs: diagnostics.silenceMs ?? 0,
        event: diagnostics.event,
    });
}

function round4(value) {
    return Math.round((Number(value) || 0) * 10000) / 10000;
}
