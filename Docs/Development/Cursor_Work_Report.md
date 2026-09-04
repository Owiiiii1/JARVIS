# Cursor work report — M24.1.1 VAD silence hotfix

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**GitHub:** `Owiiiii1/JARVIS`

No secrets, transcripts, audio samples, or provider calls.

---

## Starting HEAD

| Item | Value |
| --- | --- |
| `git fetch origin` | done |
| Local / `origin/main` at start | `de7d57915eac1b7951343c7df39255e3da96a52d` |
| Message | `feat: add assistant onboarding and reminders panel` |
| M24.1 ancestor | `7d9c83f990f95415b60d3fd8e7e33f27bd9b4f95` — **not reverted** |
| M23.2 / M25U | present; not reverted |
| Working tree at start | clean |

Worked **on top of** M25U.3, not by rolling back.

---

## Live symptom

Owner production test of M24.1:

- Text → Voice works
- microphone reacts
- Orb reacts
- status stays **Listening**
- pause after speech is not detected
- utterance never auto-finalizes

Owner: «он не понимает паузы. всегда слушает»

---

## Confirmed root cause

1. `VoiceAudioAnalyzer.rms()` returned `sqrt(mean sq) * 3.2` (Orb visual gain).
2. `rawInputRms` was that amplified value, so VAD used a visualization constant.
3. Bootstrap `noiseFloor = speechThresholdMin / noiseMultiplier` → threshold **0.048**.
4. `adaptNoise()` only ran when `rms < threshold`. Ambient already above 0.048 never updated the floor.
5. In `speech_active`, `level >= threshold` cleared `silenceStartedAt`. Steady room/mic noise above the start threshold kept the turn open forever.

Not an `endSilenceMs = 850` bug.

---

## Old RMS scaling

Physical analyser RMS × **3.2**, then clamped to 1. Same number for Orb and VAD.

---

## New architecture

- **VAD metric:** unamplified `rawInputRms` (`sqrt` only).
- **Orb metric:** `visualInputAmplitude` / `inputAmplitude` still uses **VISUAL_RMS_GAIN = 3.2**.
- **Calibration:** ~650ms ambient window at each listen `reset()`; 30th percentile; impulses do not become the floor. MediaRecorder already running — first word is not discarded. Strong rise vs evolving baseline can start speech early (`earlySpeechMultiplier = 3.0`, onset 200ms).
- **Hysteresis:** `startThreshold = clamp(noiseFloor * 2.15, 0.018, 0.32)`; `endThreshold = min(noiseFloor * 1.38, start * 0.75)` with `endThresholdMin = 0.011`.
- Speech start: `level >= startThreshold` for 200ms.
- Speech end: after speech, `level <= endThreshold` then **850ms** continuous silence (`minSpeechMs = 300`). Middle band does not reset silence; brief hold 180ms then candidate.
- Noise adapt: waiting/candidate toward ambient; speech does not raise the floor.
- Barge-in: `max(0.055, startThreshold * 1.55)` on the same unamplified scale; still conservative.
- Post-TTS / unmute: `startCapture()` → `vad.reset()` fresh cycle; optional `learnedAmbient` seed.

---

## Chosen constants

See `resources/js/voice/audio/voiceTurnDetection.js`:

- `noiseCalibrationMs = 650`
- `startNoiseMultiplier = 2.15` / `endNoiseMultiplier = 1.38`
- `speechOnsetMs = 200` / `endSilenceMs = 850` / `minSpeechMs = 300`
- `maxWaitingSegmentMs = 14000` / `maxUtteranceMs = 30000` (workspace may override seconds)

---

## Hard max

rAF still finalizes at `max_utterance_seconds` (default 30s) even if VAD phase is stuck. Detector also emits `max_utterance` when `speechDetected` and segment age ≥ max. No-speech wait still `recycle` without STT.

---

## Synthetic scenarios

`node resources/js/voice/audio/vadScenarios.js` — **all PASS**:

| ID | Result |
| --- | --- |
| A quiet 0.01 / speech 0.08 / silence 0.01 | speech_start + end_of_turn |
| B noisy 0.06 / 0.16 / 0.06 | ambient not perpetual speech; turn ends |
| C 0.10 / 0.24 / 0.10 | distinguishes |
| D 500ms pause | no end_of_turn |
| E 1000ms pause | end_of_turn |
| F 15s ambient | recycle, no STT |
| G speech past 2s test max | max_utterance with speechDetected |

---

## Debug

`?voice_debug=1` → throttled `console.debug('[voice_vad]', { rms, noiseFloor, startThreshold, endThreshold, phase, speechDetected, silenceMs, event })`. Off by default. No samples/transcripts/secrets.

---

## Checks

- `node resources/js/voice/audio/vadScenarios.js` — pass
- `npm run build` — pass (this commit)
- **TESTS NOT RUN** (`php artisan test` / PHPUnit not run)
- **NO LIVE STT / TTS / Conversation AI**

---

## Owner next

Live-test Voice pause detection in a real room. Use `?voice_debug=1` if another tuning pass is needed.
