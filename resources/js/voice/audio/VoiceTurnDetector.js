import { VOICE_TURN_DETECTION, speechThreshold } from './voiceTurnDetection';

export class VoiceTurnDetector {
    constructor(config = VOICE_TURN_DETECTION) {
        this.config = { ...VOICE_TURN_DETECTION, ...config };
        this.reset();
    }

    reset(now = performance.now()) {
        this.phase = 'waiting_for_speech';
        this.noiseFloor = this.config.speechThresholdMin / this.config.noiseMultiplier;
        this.speechDetected = false;
        this.onsetStartedAt = null;
        this.silenceStartedAt = null;
        this.speechStartedAt = null;
        this.segmentStartedAt = now;
        this.bargeOnsetStartedAt = null;
    }

    threshold() {
        return speechThreshold(this.noiseFloor, this.config);
    }

    adaptNoise(rms) {
        if (rms < this.threshold()) {
            const rate = rms > this.noiseFloor ? this.config.noiseAdaptUp : this.config.noiseAdaptDown;
            this.noiseFloor += (rms - this.noiseFloor) * rate;
        }
    }

    /**
     * @param {'listen' | 'barge-in'} mode
     * @returns {{ event: string|null, phase: string, speechDetected: boolean }}
     */
    tick(rms, now, mode = 'listen') {
        const level = Number.isFinite(rms) ? rms : 0;

        if (mode === 'barge-in') {
            return this.tickBargeIn(level, now);
        }

        this.adaptNoise(level);
        const threshold = this.threshold();

        if (this.phase === 'waiting_for_speech') {
            if (now - this.segmentStartedAt >= this.config.maxWaitingSegmentMs) {
                this.reset(now);

                return this.result('recycle');
            }

            if (level >= threshold) {
                if (this.onsetStartedAt === null) {
                    this.onsetStartedAt = now;
                }
                if (now - this.onsetStartedAt >= this.config.speechOnsetMs) {
                    this.phase = 'speech_active';
                    this.speechDetected = true;
                    this.speechStartedAt = this.onsetStartedAt;
                    this.onsetStartedAt = null;
                    this.silenceStartedAt = null;

                    return this.result('speech_start');
                }
            } else {
                this.onsetStartedAt = null;
            }

            return this.result(null);
        }

        if (level >= threshold) {
            this.silenceStartedAt = null;
            this.phase = 'speech_active';

            return this.result(null);
        }

        if (this.silenceStartedAt === null) {
            this.silenceStartedAt = now;
            this.phase = 'end_of_turn_candidate';
        }

        const spokenMs = this.speechStartedAt ? now - this.speechStartedAt : 0;
        const silentMs = now - this.silenceStartedAt;

        if (spokenMs >= this.config.minSpeechMs && silentMs >= this.config.endSilenceMs) {
            return this.result('end_of_turn');
        }

        return this.result(null);
    }

    tickBargeIn(rms, now) {
        const threshold = Math.max(this.config.bargeInThresholdMin, this.threshold() * 1.35);

        if (rms >= threshold) {
            if (this.bargeOnsetStartedAt === null) {
                this.bargeOnsetStartedAt = now;
            }
            if (now - this.bargeOnsetStartedAt >= this.config.bargeInOnsetMs) {
                this.bargeOnsetStartedAt = null;

                return this.result('barge_in');
            }
        } else {
            this.bargeOnsetStartedAt = null;
        }

        return this.result(null);
    }

    result(event) {
        return {
            event,
            phase: this.phase,
            speechDetected: this.speechDetected,
        };
    }
}
