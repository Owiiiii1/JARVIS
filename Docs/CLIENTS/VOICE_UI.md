# Voice UI

**Status.** MANUAL PASS with Voice Runtime (M24 Orb + M24.1 hands-free VAD + M24.1.1 silence hotfix). Owner confirmed listening, end-of-turn, STT, reply, TTS.

Voice is hands-free turn-based conversation. Mic button = mute/unmute only. Local VAD decides end of turn. After TTS, listening resumes automatically.

Voice **UI** ≠ Voice **Runtime**.

| Layer | Owns |
| --- | --- |
| Voice Runtime | sessions, STT, TTS, events — [VOICE_ARCHITECTURE.md](../VOICE_ARCHITECTURE.md) |
| Voice UI | Orb, transcript, controls, `VoiceVisualizationState` |

A speech vendor can change without rewriting the Orb. The Orb never calls `VoiceRuntimeService`.

---

## Place

Voice Mode is a mode of the selected conversation on Web Workspace. Not a separate User Space. Not a human avatar. Desktop is cancelled. Mobile is a deferred companion.

`/jarvis/chats/{id}` or `/chat/chats/{id}` Text/Voice toggle. Voice uses **that** `conversation_id`. Switching Voice → Text ends the voice session and keeps the same thread.

---

## M24 Orb

Module (Laravel/Inertia-free visualization engine):

```
resources/js/voice/
  visualization/   VoiceVisualizationState, presets, GLSL, OrbEngine, JarvisVoiceOrb, CSS fallback
  audio/           VoiceAudioAnalyzer, synthetic speaking demo
  components/      VoiceDemoDrawer
```

Component: `JarvisVoiceOrb` receives only `VoiceVisualizationState` (or a ref the engine polls). No provider names, no ElevenLabs, no Conversation AI details.

### VoiceVisualizationState

```
state
inputAmplitude          // 0..1, smoothed
outputAmplitude         // 0..1, smoothed
frequencyBands          // sub, low, lowMid, mid, highMid, high (0..1)
connectionState
transitionProgress
isMuted
reducedMotion
```

Production `state` maps from `voice_sessions.status`. Amplitudes come from the local Web Audio analyser, not the backend.

### Visual states

| State | Motion |
| --- | --- |
| idle | slow breath, low glow, subtle deformation |
| connecting | tighter sphere, aligning/rotating lines |
| listening | input amplitude + bands deform surface and filaments |
| transcribing | contraction, directional inner rotation (not thinking) |
| thinking | procedural filaments/particles; **no** fake waveform |
| speaking | output amplitude (or demo synthetic energy); stronger than idle |
| interrupted | fast smooth collapse toward listening-ready |
| muted | alive but dampened glow/deform/particles |
| error | unstable/frozen pulse, restrained warning accent; text shows the error |
| ended | energy fades; does not vanish instantly |

Interpolation: visual presets ease toward the target. Interrupt/listening are faster; idle/thinking/ended are slower.

### Layers

1. Translucent icosphere (custom vertex/fragment GLSL: noise displacement, fresnel)
2. Inner energy shell + backface glass
3. Thin flowing energy lines (not Saturn rings)
4. Subtle orbiting particles
5. Shader rim halo — no UnrealBloom (bloom filled an opaque square and washed the sphere)

Identity: cyan/steel precision core. Not a Siri rainbow clone. No OrbitControls. Subtle camera drift only.

### Audio (local, no providers)

`VoiceAudioAnalyzer`: `connectInputStream`, `connectOutputAudio`, smoothed RMS plus raw RMS for VAD, frequency bands. Shared `AudioContext` is resumed on the Text→Voice gesture. Microphone stream lives for the session except mute/end/text/unmount. Analyser does **not** archive audio.

VAD (`voiceTurnDetection.js` + `VoiceTurnDetector`): unamplified `rawInputRms` for detection (Orb uses separate visual gain 3.2). Short noise calibration (~650ms) on each listen cycle; start/end hysteresis vs noise floor; speech onset ~200ms; end-of-turn silence 850ms; min speech 300ms. Short pauses do not split a sentence. Ambient room noise becomes baseline, not perpetual speech. No-speech audio is never sent to STT. Hard max utterance still finalizes. During `speaking`, the recorder is not sending STT; barge-in uses a stronger calibrated threshold + post-TTS guard, then Interrupt + listen. `?voice_debug=1` throttles console.debug of RMS/thresholds/phase (no samples/transcripts).

Listening can visualize the mic even when STT is not configured. Speaking visualization uses playback analyser when TTS audio exists; otherwise demo synthetic output energy (marked as demo, not fake TTS).

### Demo mode

Enable with `?voice_demo=1` or `VITE_VOICE_DEMO_MODE=true`. Hidden drawer cycles all states. No speech providers required. Not shown in the normal Voice chrome.

### Fallback / performance

- No WebGL → CSS orb (`CssFallbackOrb`)
- `prefers-reduced-motion` reduces deform, particles, camera, pulses; text state remains
- DPR clamped (`min(devicePixelRatio, 2)`, lower on weak/mobile)
- Quality tiers; repeated slow frames drop halo/particles/DPR
- ResizeObserver; dispose geometries, materials, renderer, rAF, analyser

---

## Workspace client

`VoiceSession` owns session lifecycle (M23 HTTP + M24.1 VAD). Controls: **Mute/Unmute**, Interrupt (when speaking/thinking), End, Text. No second mic. No “Send utterance”. Labels: Listening… / Thinking… / Speaking… / Muted.

If STT/TTS are not configured: Orb keeps working; status **Speech providers not configured.** — not a crash.

If Voice opens without a usable user gesture, a single **Enable microphone** CTA appears. After Text→Voice it should not. **Enable audio** appears only if TTS autoplay is blocked.

---

## Mobile (deferred)

If a Flutter companion is built later, keep the same `VoiceVisualizationState` semantics. Do not reuse Three.js in Flutter. Desktop is cancelled.

---

## Out of scope

- Telephony UI
- Binding visualization to a vendor SDK
- Desktop / Tauri shell
