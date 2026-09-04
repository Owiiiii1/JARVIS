# Voice UI

**Status.** M23: CSS state visualization + runtime controls in Owner Workspace. Final 3D Orb is **M24**. Voice **UI** ≠ Voice **Runtime**.

| Layer | Owns |
| --- | --- |
| Voice Runtime | sessions, STT, TTS, events — [VOICE_ARCHITECTURE.md](../VOICE_ARCHITECTURE.md) |
| Voice UI | Orb, transcript, controls, visualization state |

A speech vendor can change without rewriting the Orb.

---

## Place

Voice Mode is a mode of the selected conversation on Web Workspace, Desktop, and Mobile. Not a separate User Space. Not a human avatar.

M23 Web: `/jarvis/chats/{id}` Text/Voice toggle. Voice uses **that** `conversation_id`. Switching Voice → Text ends the voice session and keeps the same thread (transcripts already persisted as ordinary messages).

---

## M23 Workspace client

Component: `VoiceSession`. Replaces the M22 placeholder.

- Start Voice (user gesture → microphone permission; never on page load)
- End
- Mute / resume
- session state
- current/final transcript
- assistant text
- basic audio playback when TTS bytes are returned
- dynamic MediaRecorder MIME detection; unsupported-browser state
- simple CSS orb (`.jarvis-orb` + state class). Not Three.js.

Microphone capture starts only after Start Voice.

---

## Orb states (runtime + future Orb)

- idle
- connecting
- listening
- transcribing
- thinking
- speaking
- interrupted
- error
- muted
- ended

M24 target motion (not implemented now):

| State | Motion |
| --- | --- |
| idle | slow breathing |
| listening | geometry reacts to microphone amplitude / frequency bands |
| thinking | inner lines / particles move independently of audio |
| speaking | orb + many lines vibrate from **actual Jarvis output audio** |
| barge-in | speaking snaps to listening |
| connecting / error / muted | distinct, calm, readable |

Reduced-motion fallback exists for the CSS orb; Three.js reduced-motion is later.

---

## Visual identity (M24)

Desired style: dark cinematic interface; translucent / glass / plasma sphere; waveform / energy lines; glow; audio-reactive movement. Do **not** copy Siri literally.

Web / Desktop tech for M24: Three.js / WebGL, custom GLSL, Web Audio analyser. Visualization is **not** bound to a voice provider.

---

## Input contract (future Orb)

```
VoiceVisualizationState
  state
  inputAmplitude
  outputAmplitude
  frequencyBands
  connectionState
```

M23 exposes `state` from `voice_sessions.status`. Amplitude/frequency are placeholders until M24.

---

## Out of scope now

- Implementing Three.js / Flutter Orb
- Shipping shaders
- Binding a live analyser to production
- Telephony UI
