# Cursor Work Report — M24.1 Hands-Free Voice + VAD + Audio Compatibility

**Date:** 2026-09-04  
**Repo:** `/var/www/jarvis` (`Owiiiii1/JARVIS`)  
**Commit intent:** `feat: add hands free voice turn detection`

---

## Starting git

| Item | Value |
| --- | --- |
| `git fetch origin` | done |
| Working tree at start | clean |
| `origin/main` HEAD | `72b4e3c010d58d7979c572b8f646f35d0a6f482e` |
| That commit | `feat: complete user administration and isolation controls` (M25U.2) |
| M25U.1 | present (ancestor) |
| M23.2 Gemini STT | `00b54e06ca2df474a6258546cd5d17221f154f77` — **kept, not reverted** |
| Uncommitted leftover | none |

Production DB. No migrations. No destructive tests.

---

## Original two-microphone UX

Primary mic: Start Voice / Start listening / Send utterance (push-to-talk).  
Second mic: Mute/Unmute.

That is removed. One control remains: Mic = listening, MicOff = muted.

---

## Unsupported format — root cause

`MediaRecorder` could select `audio/webm`, `audio/ogg`, or `audio/mp4` (often with `;codecs=opus`), but upload always used filename `utterance.webm` and passed the raw `recorder.mimeType`. Server/file sniffing could then label the bytes as webm while the container was ogg/mp4, or send a codec suffix into validation. Gemini STT then rejected the payload (`voice_audio_format_unsupported`).

### MIME normalization implemented

`VoiceAudioMime` (PHP) + `resources/js/voice/audio/mime.js`:

- raw `audio/webm;codecs=opus` → canonical `audio/webm` → `utterance.webm`
- `audio/ogg;codecs=opus` → `audio/ogg` → `utterance.ogg`
- `audio/mp4` → `audio/mp4` → `utterance.m4a`

Workspace `voiceClient.recorder_mime_candidates` is **browser preference ∩ active STT allowlist** (Gemini includes webm/ogg/mp4). Chrome/Edge typically keeps Opus/WebM when Gemini is selected.

Validation uses uploaded-file MIME, then a safe client canonical fallback. Format-reject logs: raw MIME, canonical MIME, extension, `audio_bytes`, STT provider/model. No audio content, transcripts, or secrets.

---

## Invalid-state on unmute — cause and fix

Backend `resume` is **muted → idle**, not listening. The old client then always POSTed `listen`. Duplicate/racy `listen` (or `listen` while already listening after a stale snapshot) produced `voice_session_invalid_state`.

Fix: after `resume`, call `listen` **only if** status is `idle`. Never `listen` when already `listening`. Client operations are ref-locked (no duplicate create/listen/mute/resume/interrupt/destroy/upload). On `voice_session_invalid_state`, GET session snapshot and reconcile; if ended, restart that Voice session without a full page reload.

---

## VAD

Local only (`VoiceTurnDetector` + `VoiceAudioAnalyzer.rawInputRms`). No cloud VAD. No continuous vendor stream.

Internal phases: `waiting_for_speech` → `speech_active` → `end_of_turn_candidate` (UI stays “Listening…”).

Defaults (`voiceTurnDetection.js`):

| Key | Value |
| --- | --- |
| speech onset | 200 ms |
| end silence | **850 ms** |
| min speech | 300 ms |
| max wait without speech | 14 s (recycle recorder, no STT) |
| max utterance | backend `max_utterance_seconds` (default 30s) |
| noise | adaptive floor × 3.4, clamped |
| barge-in | threshold min 0.13, 280 ms, 480 ms post-TTS guard |

No-speech recordings are never uploaded. Short pauses do not split a turn.

---

## Lifecycle

Text→Voice click primes mic + AudioContext, mounts `VoiceSession`, auto-starts **once** (generation ref). Listening begins without a second click.

After VAD end-of-turn: stop recorder → Blob → STT → thinking → speaking.

After TTS `ended`: `listen` if needed → fresh recorder/VAD.

Mute: discard unsent audio, disable tracks, POST mute. Unmute: restore tracks, resume→idle, one listen, VAD.

End / Text: discard, stop tracks/TTS, destroy session once.

Conversation switch: cleanup ends the old session; pending audio is not uploaded into the new chat; if Voice stays selected, a new session starts.

---

## Barge-in

**Implemented (conservative), not faked.** During `speaking` the STT recorder is off. Mic stays available for the analyser. Sustained loud input after a post-TTS guard stops playback, POSTs interrupt (only from speaking/thinking), then listen + capture. Echo cancellation on. Manual Interrupt button remains as fallback (disabled outside interruptible states).

---

## Error recovery

Recoverable: unsupported format, STT rate limit/timeout/fail, TTS fail, stale invalid state → notice + return to listening when possible.

Fatal-ish: permission revoked → Enable microphone CTA; session missing/ended → restart Voice session; providers unconfigured → visual listening, no STT.

---

## Static checks

- `vendor/bin/pint --dirty`
- `npm run build`
- `php artisan route:list` (voice routes unchanged aside from optional mime fields)
- static state-machine inspection

## Not run

- `php artisan test` / PHPUnit
- live Gemini STT
- live ElevenLabs
- live Conversation AI

---

## Manual validation checklist (NOT RUN)

1. Open text chat.  
2. Click Voice once.  
3. Browser asks mic permission if needed.  
4. No second mic click.  
5. Orb listens.  
6. Say “Привет, как дела?”  
7. Stop speaking.  
8. After ~850ms, transcription starts.  
9. Jarvis replies.  
10. ElevenLabs speaks.  
11. After playback, automatic Listening.  
12. Second phrase without touching UI.  
13. Second turn works.  
14. Mic → Muted.  
15. Speech while muted: no STT.  
16. Unmute.  
17. Listening without invalid state.  
18. Short pause inside a sentence does not split the turn.  
19. Silence does not create a blank message.  
20. End closes mic/session.  
21. Text switches back safely.  
22. Chrome recorder MIME is accepted.  
23. No `voice_session_invalid_state`.  
24. No `voice_audio_format_unsupported` for a supported browser format.

Owner should run this live after merge.

No secrets, transcripts, or audio in this report.
