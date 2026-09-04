# Mobile client

**Status.** DOCUMENTED ONLY. Not implemented.

Thin mobile client to Jarvis Core. iOS and Android. Source of truth remains the backend.

---

## Repository

Recommended GitHub repo: **`Owiiiii1/JARVIS-Mobile`**

- Developed locally, pushed to GitHub.
- Flutter iOS/Android build and store lifecycle are separate from Laravel and Tauri.
- Master API / client protocol stays in `Owiiiii1/JARVIS` ([CLIENT_API.md](CLIENT_API.md)).

---

## Stack

Flutter, latest stable. Platforms: iOS, Android.

Orb / voice visualization on mobile uses a Flutter renderer equivalent to the WebGL Orb concept. Visual identity and `VoiceVisualizationState` follow [VOICE_UI.md](VOICE_UI.md). Do not reuse the Three.js module in Flutter.

---

## Capabilities

- same conversations as Telegram / Workspace / Desktop
- text chat
- voice chat + live transcript
- Orb visualization
- reminders (create via Core tools; delivery policy remains server-side)
- push notifications later
- project context via server tools
- integrations **through server tools only**
- tool confirmations
- microphone / audio routing

---

## What Mobile must not do

- Call Google / Gmail / Calendar / GitHub APIs directly
- Store provider tokens
- Run Memory Engine or Tool Registry locally
- Create a second assistant or a silent voice conversation

---

## Conversation continuity

One `conversation_id`. Voice is a mode. New Chat only when the user asks.

---

## Auth

`TBD` against versioned Client API. Same `user_id`. Access code is Telegram pairing, not mobile login.

---

## Out of scope now

- Creating the Flutter project
- App Store / Play setup
- Voice provider SDKs on device beyond transport to Core
