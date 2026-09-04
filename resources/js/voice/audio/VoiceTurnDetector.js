import {
    VOICE_TURN_DETECTION,
    bargeInThreshold,
    endThreshold,
    percentile,
    startThreshold,
} from './voiceTurnDetection.js';

export class VoiceTurnDetector {
    constructor(config = {}) {
        this.config = { ...VOICE_TURN_DETECTION, ...config };
        this.learnedAmbient = null;
        this.reset(0);
    }

    reset(now = 0, { seedNoiseFloor } = {}) {
        const seed = Number.isFinite(seedNoiseFloor)
            ? seedNoiseFloor
            : this.learnedAmbient;

        this.phase = 'calibrating';
        this.noiseFloor = Number.isFinite(seed) && seed > 0
            ? seed
            : this.config.startThresholdMin / this.config.startNoiseMultiplier;
        this.speechDetected = false;
        this.onsetStartedAt = null;
        this.silenceStartedAt = null;
        this.speechStartedAt = null;
        this.middleStartedAt = null;
        this.segmentStartedAt = now;
        this.calibrationStartedAt = now;
        this.calibrationSamples = [];
        this.bargeOnsetStartedAt = null;
        this.lastRms = 0;
    }

    startLevel() {
        return startThreshold(this.noiseFloor, this.config);
    }

    endLevel() {
        return endThreshold(this.noiseFloor, this.config);
    }

    bargeLevel() {
        return bargeInThreshold(this.noiseFloor, this.config);
    }

    adaptNoise(level) {
        const start = this.startLevel();
        const end = this.endLevel();

        if (this.phase === 'speech_active' && level > end) {
            if (level < this.noiseFloor) {
                this.noiseFloor += (level - this.noiseFloor) * this.config.speechNoiseAdapt;
            }

            return;
        }

        if (this.phase === 'speech_active') {
            return;
        }

        if (level > start) {
            return;
        }

        const up = this.phase === 'end_of_turn_candidate'
            ? this.config.candidateNoiseAdaptUp
            : this.config.noiseAdaptUp;
        const rate = level > this.noiseFloor ? up : this.config.noiseAdaptDown;
        this.noiseFloor += (level - this.noiseFloor) * rate;
        this.noiseFloor = Math.max(0.0005, this.noiseFloor);
    }

    finishCalibration() {
        if (this.calibrationSamples.length >= this.config.minCalibrationSamples) {
            this.noiseFloor = Math.max(0.0005, percentile(this.calibrationSamples, 0.3));
        }

        this.learnedAmbient = this.noiseFloor;
        this.phase = 'waiting_for_speech';
        this.onsetStartedAt = null;
    }

    /**
     * @param {'listen' | 'barge-in'} mode
     * @returns {{
     *   event: string|null,
     *   phase: string,
     *   speechDetected: boolean,
     *   rms: number,
     *   noiseFloor: number,
     *   startThreshold: number,
     *   endThreshold: number,
     *   silenceMs: number,
     * }}
     */
    tick(rms, now, mode = 'listen') {
        const level = Number.isFinite(rms) && rms > 0 ? rms : 0;
        this.lastRms = level;

        if (mode === 'barge-in') {
            return this.tickBargeIn(level, now);
        }

        if (this.speechDetected && now - this.segmentStartedAt >= this.config.maxUtteranceMs) {
            return this.result('max_utterance', now);
        }

        if (this.phase === 'calibrating') {
            return this.tickCalibrating(level, now);
        }

        this.adaptNoise(level);

        if (this.phase === 'waiting_for_speech') {
            return this.tickWaiting(level, now);
        }

        return this.tickSpeech(level, now);
    }

    tickCalibrating(level, now) {
        this.calibrationSamples.push(level);

        if (this.calibrationSamples.length >= this.config.minCalibrationSamples) {
            const evolving = Math.max(0.0005, percentile(this.calibrationSamples, 0.3));
            const earlyStart = Math.max(
                this.config.startThresholdMin * 1.8,
                evolving * this.config.earlySpeechMultiplier,
            );

            if (level >= earlyStart) {
                if (this.onsetStartedAt === null) {
                    this.onsetStartedAt = now;
                }
                if (now - this.onsetStartedAt >= this.config.speechOnsetMs) {
                    this.noiseFloor = evolving;
                    this.learnedAmbient = evolving;
                    this.enterSpeech(this.onsetStartedAt);

                    return this.result('speech_start', now);
                }
            } else {
                this.onsetStartedAt = null;
            }
        }

        if (now - this.calibrationStartedAt >= this.config.noiseCalibrationMs) {
            this.finishCalibration();
        }

        if (now - this.segmentStartedAt >= this.config.maxWaitingSegmentMs) {
            this.learnedAmbient = this.noiseFloor;
            this.reset(now);

            return this.result('recycle', now);
        }

        return this.result(null, now);
    }

    tickWaiting(level, now) {
        if (now - this.segmentStartedAt >= this.config.maxWaitingSegmentMs) {
            this.learnedAmbient = this.noiseFloor;
            this.reset(now);

            return this.result('recycle', now);
        }

        const start = this.startLevel();

        if (level >= start) {
            if (this.onsetStartedAt === null) {
                this.onsetStartedAt = now;
            }
            if (now - this.onsetStartedAt >= this.config.speechOnsetMs) {
                this.enterSpeech(this.onsetStartedAt);

                return this.result('speech_start', now);
            }
        } else {
            this.onsetStartedAt = null;
        }

        return this.result(null, now);
    }

    tickSpeech(level, now) {
        const start = this.startLevel();
        const end = this.endLevel();

        if (level >= start) {
            this.silenceStartedAt = null;
            this.middleStartedAt = null;
            this.phase = 'speech_active';

            return this.result(null, now);
        }

        if (level <= end) {
            this.middleStartedAt = null;
            if (this.silenceStartedAt === null) {
                this.silenceStartedAt = now;
                this.phase = 'end_of_turn_candidate';
            }
        } else if (this.phase === 'end_of_turn_candidate') {
            // Middle band: do not return to speech or reset silence.
        } else {
            if (this.middleStartedAt === null) {
                this.middleStartedAt = now;
            }
            if (now - this.middleStartedAt >= this.config.middleHoldMs) {
                this.silenceStartedAt = this.silenceStartedAt ?? now;
                this.phase = 'end_of_turn_candidate';
            }
        }

        const spokenMs = this.speechStartedAt ? now - this.speechStartedAt : 0;
        const silentMs = this.silenceStartedAt ? now - this.silenceStartedAt : 0;

        if (this.phase === 'end_of_turn_candidate'
            && spokenMs >= this.config.minSpeechMs
            && silentMs >= this.config.endSilenceMs) {
            this.learnedAmbient = this.noiseFloor;

            return this.result('end_of_turn', now);
        }

        return this.result(null, now);
    }

    enterSpeech(startedAt) {
        this.phase = 'speech_active';
        this.speechDetected = true;
        this.speechStartedAt = startedAt;
        this.onsetStartedAt = null;
        this.silenceStartedAt = null;
        this.middleStartedAt = null;
    }

    tickBargeIn(rms, now) {
        const threshold = this.bargeLevel();

        if (rms >= threshold) {
            if (this.bargeOnsetStartedAt === null) {
                this.bargeOnsetStartedAt = now;
            }
            if (now - this.bargeOnsetStartedAt >= this.config.bargeInOnsetMs) {
                this.bargeOnsetStartedAt = null;

                return this.result('barge_in', now);
            }
        } else {
            this.bargeOnsetStartedAt = null;
        }

        return this.result(null, now);
    }

    result(event, now = 0) {
        return {
            event,
            phase: this.phase === 'calibrating' ? 'waiting_for_speech' : this.phase,
            vadPhase: this.phase,
            speechDetected: this.speechDetected,
            rms: this.lastRms,
            noiseFloor: this.noiseFloor,
            startThreshold: this.startLevel(),
            endThreshold: this.endLevel(),
            silenceMs: this.silenceStartedAt ? Math.max(0, now - this.silenceStartedAt) : 0,
        };
    }
}
