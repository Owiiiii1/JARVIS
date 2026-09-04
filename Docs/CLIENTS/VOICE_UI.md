# Voice UI

**Status.** DOCUMENTED ONLY. Not implemented.

Voice **UI** ≠ Voice **Runtime**.

| Layer | Owns |
| --- | --- |
| Voice Runtime | audio transport, STT, TTS, realtime provider — [VOICE_ARCHITECTURE.md](../VOICE_ARCHITECTURE.md) |
| Voice UI | Orb, transcript, controls, visualization state |

A speech vendor can change without rewriting the Orb.

---

## Place

Voice Mode is a mode of the selected conversation on Web Workspace, Desktop, and Mobile. Not a separate User Space. Not a human avatar.

Center of Voice Mode: an animated **3D Orb** — the visual representation of Jarvis.

---

## Orb states

- idle
- connecting
- listening
- thinking
- speaking
- interrupted
- error
- muted

### Behaviour

| State | Motion |
| --- | --- |
| idle | slow breathing |
| listening | geometry reacts to microphone amplitude / frequency bands |
| thinking | inner lines / particles move independently of audio |
| speaking | orb + many lines vibrate from **actual Jarvis output audio** |
| barge-in | speaking snaps to listening |
| connecting / error / muted | distinct, calm, readable |

Reduced-motion fallback is required later.

---

## Visual identity

Desired style:

- dark cinematic interface
- translucent / glass / plasma sphere
- many thin waveform / energy lines
- lines deform around and inside the sphere
- glow / bloom
- subtle particles
- audio-reactive movement

Do **not** copy Siri literally. Final Orb must be a **Jarvis** identity.

Reference directions (concepts only — do not copy assets or code):

- Siri-style audio-reactive orb / wave
- assistant-ui Orb
- orb-ui voice agent UI
- voiceorbs Nebula / Waveform Ring / Liquid Metal
- Three.js + GLSL realtime audio-reactive orb write-ups

---

## Web / Desktop tech

- Three.js / WebGL
- custom GLSL shaders
- Web Audio analyser

Visualization is **not** bound to a voice provider.

---

## Input contract

```
VoiceVisualizationState
  state
  inputAmplitude
  outputAmplitude
  frequencyBands
  connectionState
```

Runtime (ElevenLabs / OpenAI / Gemini / other) maps audio and session status into this struct. UI reads only this struct.

---

## Controls (minimum later)

- mute / unmute
- end session
- switch to text (same conversation)
- interrupt affordance if barge-in fails

Transcript is live and persisted as final text in `messages` when the turn commits. Partial hypotheses may show in UI only (`TBD`).

---

## Out of scope now

- Implementing Three.js / Flutter Orb
- Shipping shaders
- Binding a live analyser to production
