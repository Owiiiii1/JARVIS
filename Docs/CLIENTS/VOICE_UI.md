# Voice UI

**Status.** M24 IMPLEMENTED / NOT VALIDATED (2026-09-04). M23.2 Gemini STT is Admin infrastructure; the Orb/Workspace UI stays provider-neutral. M25U.1: same Three.js Orb on Owner `/jarvis` and User `/chat` Shared Personal Workspace. No live STT/TTS. Automated tests not run. Ordinary users do not see a Gemini vendor label. When STT/TTS are configured, the generic **Speech providers not configured** notice is not shown.

Voice **UI** ≠ Voice **Runtime**.

| Layer | Owns |
| --- | --- |
| Voice Runtime | sessions, STT, TTS, events — [VOICE_ARCHITECTURE.md](../VOICE_ARCHITECTURE.md) |
| Voice UI | Orb, transcript, controls, `VoiceVisualizationState` |

A speech vendor can change without rewriting the Orb. The Orb never calls `VoiceRuntimeService`.

---

## Place

Voice Mode is a mode of the selected conversation on Web Workspace (and later Desktop / Mobile). Not a separate User Space. Not a human avatar.

`/jarvis/chats/{id}` or `/chat/chats/{id}` Text/Voice toggle. Voice uses **that** `conversation_id`. Switching Voice → Text ends the voice session and keeps the same thread.

---

## M24 Orb

Module (Laravel/Inertia-free visualization engine, reusable by Desktop):

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

`VoiceAudioAnalyzer`: `connectInputStream`, `connectOutputAudio`, smoothed RMS, frequency bands. Microphone only after Start Voice. Tracks stop on End / Text / unmount. Analyser does **not** archive audio.

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

`VoiceSession` still owns session lifecycle (M23 HTTP). Controls: Start Voice, Mute, Interrupt, End, Text. Readable state label. Latest user phrase + latest assistant line only (full history stays in Text mode).

If STT/TTS are not configured: Orb keeps working; status **Speech providers not configured.** — not a crash.

---

## Desktop / Mobile

Desktop (Tauri + React) should import `resources/js/voice/visualization` (or a copy) — no Inertia inside the engine.

Flutter Orb is a separate renderer. Keep the same `VoiceVisualizationState` semantics. Do not reuse Three.js in Flutter.

---

## Out of scope

- Telephony UI
- Live STT/TTS validation
- Binding visualization to a vendor SDK
