const BASE = {
    scale: 1,
    opacity: 1,
    glow: 0.22,
    deform: 0.08,
    breath: 0.35,
    lineSpeed: 0.14,
    particleSpeed: 0.12,
    innerSpin: 0.18,
    saturate: 1,
    warning: 0,
    tightness: 0,
    fade: 0,
    audioGain: 0,
    audioSource: 'none',
    lerp: 0.05,
};

export const STATE_PRESETS = {
    idle: {
        ...BASE,
        glow: 0.22,
        deform: 0.07,
        breath: 0.42,
        lineSpeed: 0.1,
        particleSpeed: 0.08,
        lerp: 0.035,
    },
    connecting: {
        ...BASE,
        scale: 0.92,
        glow: 0.3,
        deform: 0.04,
        breath: 0.18,
        lineSpeed: 0.72,
        particleSpeed: 0.22,
        tightness: 0.7,
        innerSpin: 0.55,
        lerp: 0.08,
    },
    listening: {
        ...BASE,
        glow: 0.36,
        deform: 0.18,
        breath: 0.16,
        lineSpeed: 0.36,
        particleSpeed: 0.28,
        audioGain: 1,
        audioSource: 'input',
        lerp: 0.14,
    },
    transcribing: {
        ...BASE,
        scale: 0.94,
        glow: 0.26,
        deform: 0.1,
        breath: 0.1,
        lineSpeed: 0.88,
        particleSpeed: 0.18,
        tightness: 0.45,
        innerSpin: 0.7,
        lerp: 0.1,
    },
    thinking: {
        ...BASE,
        glow: 0.34,
        deform: 0.16,
        breath: 0.22,
        lineSpeed: 0.32,
        particleSpeed: 0.48,
        innerSpin: 0.62,
        audioGain: 0,
        audioSource: 'none',
        lerp: 0.04,
    },
    speaking: {
        ...BASE,
        scale: 1.04,
        glow: 0.44,
        deform: 0.22,
        breath: 0.18,
        lineSpeed: 0.44,
        particleSpeed: 0.4,
        audioGain: 1,
        audioSource: 'output',
        lerp: 0.1,
    },
    interrupted: {
        ...BASE,
        scale: 0.9,
        glow: 0.32,
        deform: 0.14,
        breath: 0.2,
        lineSpeed: 0.95,
        particleSpeed: 0.55,
        tightness: 0.35,
        lerp: 0.22,
    },
    muted: {
        ...BASE,
        glow: 0.08,
        deform: 0.03,
        breath: 0.16,
        lineSpeed: 0.05,
        particleSpeed: 0.04,
        saturate: 0.35,
        innerSpin: 0.08,
        lerp: 0.07,
    },
    error: {
        ...BASE,
        glow: 0.22,
        deform: 0.05,
        breath: 0.08,
        lineSpeed: 0.06,
        particleSpeed: 0.03,
        warning: 0.7,
        saturate: 0.55,
        lerp: 0.09,
    },
    ended: {
        ...BASE,
        scale: 0.82,
        opacity: 0.08,
        glow: 0.04,
        deform: 0.02,
        breath: 0.04,
        lineSpeed: 0.03,
        particleSpeed: 0.02,
        fade: 1,
        lerp: 0.03,
    },
};

export function presetFor(state) {
    return STATE_PRESETS[state] ?? STATE_PRESETS.idle;
}

export function lerp(from, to, t) {
    return from + (to - from) * t;
}

export function damp(current, target, rate) {
    return lerp(current, target, Math.min(1, Math.max(0.01, rate)));
}

export function createVisualParams() {
    return { ...STATE_PRESETS.idle };
}

export function stepVisualParams(current, targetState, dt) {
    const target = presetFor(targetState);
    const speed = Math.min(1, target.lerp * (dt / 0.016));
    const next = { ...current };

    for (const key of Object.keys(target)) {
        if (typeof target[key] === 'number' && typeof current[key] === 'number') {
            next[key] = damp(current[key], target[key], speed);
        } else {
            next[key] = target[key];
        }
    }

    return next;
}
