export function emptyBands() {
    return {
        sub: 0,
        low: 0,
        lowMid: 0,
        mid: 0,
        highMid: 0,
        high: 0,
    };
}

function meanRange(data, start, end) {
    const from = Math.max(0, Math.min(data.length - 1, start));
    const to = Math.max(from + 1, Math.min(data.length, end));
    let sum = 0;

    for (let i = from; i < to; i += 1) {
        sum += data[i];
    }

    return sum / (to - from) / 255;
}

function smooth(previous, next, attack, release) {
    const rate = next > previous ? attack : release;

    return previous + (next - previous) * rate;
}

/**
 * Browser-only Web Audio analyser. Does not record or upload audio.
 */
export class VoiceAudioAnalyzer {
    constructor() {
        this.ctx = null;
        this.inputAnalyser = null;
        this.outputAnalyser = null;
        this.inputSource = null;
        this.outputSource = null;
        this.freqIn = null;
        this.timeIn = null;
        this.freqOut = null;
        this.timeOut = null;
        this.inputAmplitude = 0;
        this.outputAmplitude = 0;
        this.frequencyBands = emptyBands();
        this.outputBands = emptyBands();
    }

    ensureContext() {
        if (typeof window === 'undefined') {
            return null;
        }

        if (! this.ctx) {
            const Ctor = window.AudioContext || window.webkitAudioContext;
            if (! Ctor) {
                return null;
            }

            this.ctx = new Ctor();
            this.inputAnalyser = this.ctx.createAnalyser();
            this.outputAnalyser = this.ctx.createAnalyser();
            this.inputAnalyser.fftSize = 1024;
            this.outputAnalyser.fftSize = 1024;
            this.inputAnalyser.smoothingTimeConstant = 0.62;
            this.outputAnalyser.smoothingTimeConstant = 0.55;
            this.freqIn = new Uint8Array(this.inputAnalyser.frequencyBinCount);
            this.timeIn = new Uint8Array(this.inputAnalyser.fftSize);
            this.freqOut = new Uint8Array(this.outputAnalyser.frequencyBinCount);
            this.timeOut = new Uint8Array(this.outputAnalyser.fftSize);
        }

        if (this.ctx.state === 'suspended') {
            this.ctx.resume().catch(() => {});
        }

        return this.ctx;
    }

    connectInputStream(stream) {
        const ctx = this.ensureContext();
        if (! ctx || ! stream) {
            return;
        }

        this.disconnectInput();
        this.inputSource = ctx.createMediaStreamSource(stream);
        this.inputSource.connect(this.inputAnalyser);
    }

    connectOutputAudio(element) {
        const ctx = this.ensureContext();
        if (! ctx || ! element) {
            return;
        }

        try {
            if (! element._jarvisMediaSource) {
                element._jarvisMediaSource = ctx.createMediaElementSource(element);
            }
            this.outputSource = element._jarvisMediaSource;
            this.outputSource.connect(this.outputAnalyser);
            this.outputAnalyser.connect(ctx.destination);
        } catch {
            // Element already connected to another context; skip.
        }
    }

    disconnectInput() {
        try {
            this.inputSource?.disconnect();
        } catch {
            // ignore
        }
        this.inputSource = null;
    }

    disconnectOutput() {
        try {
            this.outputSource?.disconnect();
            this.outputAnalyser?.disconnect();
        } catch {
            // ignore
        }
        this.outputSource = null;
    }

    rms(analyser, timeBytes) {
        if (! analyser || ! timeBytes) {
            return 0;
        }

        analyser.getByteTimeDomainData(timeBytes);
        let sum = 0;

        for (let i = 0; i < timeBytes.length; i += 1) {
            const v = (timeBytes[i] - 128) / 128;
            sum += v * v;
        }

        return Math.min(1, Math.sqrt(sum / timeBytes.length) * 3.2);
    }

    bandsFrom(analyser, freqBytes) {
        if (! analyser || ! freqBytes) {
            return emptyBands();
        }

        analyser.getByteFrequencyData(freqBytes);
        const n = freqBytes.length;

        return {
            sub: meanRange(freqBytes, 0, Math.floor(n * 0.04)),
            low: meanRange(freqBytes, Math.floor(n * 0.04), Math.floor(n * 0.12)),
            lowMid: meanRange(freqBytes, Math.floor(n * 0.12), Math.floor(n * 0.28)),
            mid: meanRange(freqBytes, Math.floor(n * 0.28), Math.floor(n * 0.5)),
            highMid: meanRange(freqBytes, Math.floor(n * 0.5), Math.floor(n * 0.72)),
            high: meanRange(freqBytes, Math.floor(n * 0.72), n),
        };
    }

    tick() {
        const inputRms = this.rms(this.inputAnalyser, this.timeIn);
        const outputRms = this.rms(this.outputAnalyser, this.timeOut);
        this.inputAmplitude = smooth(this.inputAmplitude, inputRms, 0.38, 0.08);
        this.outputAmplitude = smooth(this.outputAmplitude, outputRms, 0.34, 0.1);
        this.frequencyBands = this.bandsFrom(this.inputAnalyser, this.freqIn);
        this.outputBands = this.bandsFrom(this.outputAnalyser, this.freqOut);

        return {
            inputAmplitude: this.inputAmplitude,
            outputAmplitude: this.outputAmplitude,
            frequencyBands: this.frequencyBands,
            outputBands: this.outputBands,
        };
    }

    dispose() {
        this.disconnectInput();
        this.disconnectOutput();
        if (this.ctx && this.ctx.state !== 'closed') {
            this.ctx.close().catch(() => {});
        }
        this.ctx = null;
        this.inputAnalyser = null;
        this.outputAnalyser = null;
        this.freqIn = null;
        this.timeIn = null;
        this.freqOut = null;
        this.timeOut = null;
        this.inputAmplitude = 0;
        this.outputAmplitude = 0;
        this.frequencyBands = emptyBands();
        this.outputBands = emptyBands();
    }
}
