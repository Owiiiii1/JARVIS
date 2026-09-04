# Cursor Work Report — M24 Voice UI / Orb

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**Public URL:** https://jarvis.owlsolutions.net  
**GitHub:** https://github.com/Owiiiii1/JARVIS.git  
**Branch:** `main`

---

## Before

Origin/main HEAD before this work:

`54cd569be7320ebfc908eb28e63e703e40c497ab`  
`feat: add voice runtime foundation`

M23 Voice Runtime Foundation already shipped. Speech providers remain unconfigured and were not connected or live-validated in this milestone.

---

## Dependencies

Production frontend: `three` (installed via npm). No extra UI framework. No PHP / DB tables.

---

## Module structure

```
resources/js/voice/
  visualization/   VoiceVisualizationState, state presets, GLSL, OrbEngine, JarvisVoiceOrb, CssFallbackOrb
  audio/           VoiceAudioAnalyzer, synthetic speaking demo
  components/      VoiceDemoDrawer
  demo.js          ?voice_demo=1 / VITE_VOICE_DEMO_MODE
```

Visualization has no Inertia / Laravel imports. `VoiceSession` remains the Workspace session client and does not live inside the renderer.

---

## Shader / layers

Custom vertex + fragment GLSL (value-noise fbm, fresnel, amplitude uniforms). Icosahedron (detail 2–3). Layers: translucent sphere, inner energy shell, flowing energy lines, restrained particles, optional UnrealBloom on the high tier.

Presets for all M23 states with eased interpolation (faster interrupt/listening, slower idle/thinking/ended). No OrbitControls. Subtle camera drift.

---

## Audio analyser

Local Web Audio after Start Voice / demo mic gesture. Smoothed RMS (attack/release). Bands: sub, low, lowMid, mid, highMid, high. `connectInputStream` / `connectOutputAudio`. No recording archive. Mic tracks stop on End, Text, unmount.

Speaking without TTS uses clearly labeled demo synthetic energy, not fake speech.

---

## Demo mode

Hidden drawer when `?voice_demo=1` or `VITE_VOICE_DEMO_MODE`. Cycles all visual states. Mic optional for listening. Does not require STT/TTS.

Provider-not-configured shows **Speech providers not configured.** Orb and controls stay usable.

---

## Workspace integration

Voice Mode layout: cinematic stage, large Orb, readable state label, latest phrase + assistant line, Start / Mute / Interrupt / End / Text. Same `conversation_id`. CSS fallback if WebGL is missing. `prefers-reduced-motion` honored. DPR clamped. ResizeObserver. Engine dispose on Text ↔ Voice.

---

## Verification

- `npm install` (three)
- `npm run build` — pass. Workspace ~40 kB; Voice/Three.js lazy chunk ~574 kB (gzip ~146 kB), loaded only in Voice Mode.

**TESTS NOT RUN** (`php artisan test` / PHPUnit not executed).

**NO LIVE STT / TTS / AI / Google / GitHub / Web smoke.**

---

## Known limitations

- Speech providers still not configured; runtime speech is not live-validated.
- Demo synthetic speaking is visualization-only.
- Flutter Orb is a later separate renderer.
- Telephony is out of scope.

---

## Next

**M25 Desktop Client Foundation.**
