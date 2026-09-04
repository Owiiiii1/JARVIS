export function webglAvailable() {
    if (typeof document === 'undefined') {
        return false;
    }

    try {
        const canvas = document.createElement('canvas');
        return Boolean(canvas.getContext('webgl2') || canvas.getContext('webgl'));
    } catch {
        return false;
    }
}

export function prefersReducedMotion() {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return false;
    }

    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function pickQualityTier() {
    if (typeof window === 'undefined') {
        return 'medium';
    }

    const width = window.innerWidth;
    const cores = navigator.hardwareConcurrency || 4;
    const memory = navigator.deviceMemory || 8;

    if (width < 768 || cores <= 4 || memory <= 4) {
        return 'low';
    }

    if (width < 1280 || cores <= 6) {
        return 'medium';
    }

    return 'high';
}

export function clampPixelRatio(tier = 'high') {
    const dpr = typeof window !== 'undefined' ? window.devicePixelRatio || 1 : 1;
    const cap = tier === 'low' ? 1.25 : 2;

    return Math.min(dpr, cap);
}
