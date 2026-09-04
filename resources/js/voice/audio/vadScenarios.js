import { VoiceTurnDetector } from './VoiceTurnDetector.js';
import { VOICE_TURN_DETECTION } from './voiceTurnDetection.js';

const FRAME_MS = 16;

function runSequence(steps, config = {}) {
    const detector = new VoiceTurnDetector(config);
    let now = 0;
    const events = [];
    detector.reset(now);

    for (const step of steps) {
        const frames = Math.max(1, Math.ceil(step.ms / FRAME_MS));

        for (let i = 0; i < frames; i += 1) {
            now += FRAME_MS;
            const result = detector.tick(step.rms, now, 'listen');
            if (result.event) {
                events.push({
                    event: result.event,
                    t: now,
                    speechDetected: result.speechDetected,
                    phase: result.phase,
                    noiseFloor: result.noiseFloor,
                    startThreshold: result.startThreshold,
                    endThreshold: result.endThreshold,
                });
            }
        }
    }

    return { detector, events, now };
}

function expect(name, ok, detail = '') {
    if (! ok) {
        throw new Error(`FAIL ${name}${detail ? `: ${detail}` : ''}`);
    }

    console.log(`PASS ${name}`);
}

function hasEvent(events, name) {
    return events.some((item) => item.event === name);
}

function eventTime(events, name) {
    return events.find((item) => item.event === name)?.t ?? null;
}

const A = runSequence([
    { rms: 0.01, ms: 700 },
    { rms: 0.08, ms: 400 },
    { rms: 0.01, ms: 900 },
]);
expect('A quiet room speech_start', hasEvent(A.events, 'speech_start'));
expect('A quiet room end_of_turn', hasEvent(A.events, 'end_of_turn'));
expect('A no recycle', ! hasEvent(A.events, 'recycle'));

const B = runSequence([
    { rms: 0.06, ms: 700 },
    { rms: 0.16, ms: 500 },
    { rms: 0.06, ms: 900 },
]);
expect('B noisy ambient is not perpetual speech', hasEvent(B.events, 'speech_start'));
expect('B return to ambient ends turn', hasEvent(B.events, 'end_of_turn'));
expect(
    'B speech starts after calibration',
    eventTime(B.events, 'speech_start') >= VOICE_TURN_DETECTION.noiseCalibrationMs,
);

const C = runSequence([
    { rms: 0.10, ms: 700 },
    { rms: 0.24, ms: 500 },
    { rms: 0.10, ms: 900 },
]);
expect('C very noisy speech_start', hasEvent(C.events, 'speech_start'));
expect('C very noisy end_of_turn', hasEvent(C.events, 'end_of_turn'));

const D = runSequence([
    { rms: 0.01, ms: 700 },
    { rms: 0.08, ms: 400 },
    { rms: 0.01, ms: 500 },
    { rms: 0.08, ms: 400 },
    { rms: 0.01, ms: 200 },
]);
expect('D short pause does not end turn', ! hasEvent(D.events, 'end_of_turn'));
expect('D still detected speech', D.detector.speechDetected === true);

const E = runSequence([
    { rms: 0.01, ms: 700 },
    { rms: 0.08, ms: 400 },
    { rms: 0.01, ms: 1000 },
]);
expect('E 1000ms silence ends turn', hasEvent(E.events, 'end_of_turn'));

const F = runSequence([
    { rms: 0.02, ms: 15000 },
]);
expect('F ambient recycle without STT', hasEvent(F.events, 'recycle'));
expect('F no end_of_turn', ! hasEvent(F.events, 'end_of_turn'));
expect('F speechDetected false', F.events.find((item) => item.event === 'recycle')?.speechDetected === false);

const G = runSequence(
    [
        { rms: 0.01, ms: 700 },
        { rms: 0.09, ms: 2500 },
    ],
    { maxUtteranceMs: 2000 },
);
expect('G hard max finalizes speech', hasEvent(G.events, 'max_utterance'));
expect('G max has speechDetected', G.events.find((item) => item.event === 'max_utterance')?.speechDetected === true);

console.log('All VAD synthetic scenarios passed.');
