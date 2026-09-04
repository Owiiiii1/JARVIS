# Cursor Work Report — Client / Voice / GitHub architecture docs

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**GitHub:** `Owiiiii1/JARVIS`  
**Branch:** `main`

Documentation-only. No runtime, route, schema, OAuth, voice provider, Tauri, Flutter, or Three.js changes.

---

## Before HEAD

`af693aa` — `feat: add Gmail tools` (M19)

---

## Documents created

- `Docs/CLIENTS/WEB_WORKSPACE.md`
- `Docs/CLIENTS/DESKTOP_APP.md`
- `Docs/CLIENTS/MOBILE_APP.md`
- `Docs/CLIENTS/CLIENT_API.md`
- `Docs/CLIENTS/VOICE_UI.md`

---

## Documents changed

- `Docs/PROJECT.md`
- `Docs/ARCHITECTURE.md`
- `Docs/CONVERSATION_ENGINE.md`
- `Docs/VOICE_ARCHITECTURE.md`
- `Docs/CHANNELS.md`
- `Docs/API.md`
- `Docs/INTEGRATIONS.md`
- `Docs/IMPLEMENTATION_PLAN.md`
- `Docs/ROADMAP.md`
- `Docs/DEVELOPMENT_PHASES.md`
- `Docs/CURRENT_STATE.md`
- `Docs/DECISIONS.md` (ADR-086–095)
- `Docs/USERS_AND_CABINET.md`

---

## Product decision

Admin Panel ≠ Personal Workspace.

Admin: users, AI providers, integrations, Telegram groups, diagnostics, tool logs, system settings.

Workspace: owner talks to Jarvis. Center = conversation (text + voice). Other panels are secondary.

Owner must not use Admin as the primary chat UI. Current `/cabinet` stays User Space. Workspace route `/workspace` or `/jarvis` is TBD and not built.

---

## Repository topology

One product, three repos:

| Repo | Role |
| --- | --- |
| `Owiiiii1/JARVIS` | Core, Admin, Cabinet, planned Workspace, master API docs |
| `Owiiiii1/JARVIS-Desktop` | Tauri 2 client, own releases |
| `Owiiiii1/JARVIS-Mobile` | Flutter iOS/Android, store lifecycle |

Reasons: toolchains, release cycles, deploy targets, CI, Cursor context, store/updater isolation. Production Laravel tree must not contain Tauri/Rust or Flutter.

---

## Client stacks

- Web Workspace: existing Laravel + Inertia/React in JARVIS
- Desktop: Tauri 2 + React + TypeScript + Vite + Three.js/WebGL
- Mobile: Flutter latest stable
- Protocol: versioned Client API in JARVIS; clients are thin

None implement Memory Engine, tools, or Google/GitHub credentials locally.

---

## Voice UI concept

Voice Runtime ≠ Voice UI.

Orb: 3D animated Jarvis identity (not a human avatar). States: idle, connecting, listening, thinking, speaking, interrupted, error, muted. Dark cinematic glass/plasma sphere, many energy lines, audio-reactive, barge-in snap. Provider-neutral `VoiceVisualizationState`. References are direction only; final look is original Jarvis.

---

## GitHub roadmap

M21, owner-only, Integration Framework. Read first (repos, commits, files, issues, PRs, workflows). Controlled write later. Use cases: what changed, last commit, find a class, open issues, diffs, create issue. Not implemented.

---

## Revised milestone order

| # | Focus |
| --- | --- |
| M20 | Combined Google smoke / hardening (validation) |
| M21 | GitHub Integration |
| M22 | Owner Web Workspace (+ Client API foundation) |
| M23 | Voice Runtime Foundation |
| M24 | Voice UI / Orb |
| M25 | Desktop Client Foundation |
| M26 | Mobile Client Foundation |
| M27 | Proactive assistant / monitoring |
| M28+ | Human-like / polish / wake word / files |

M0–M19 unchanged and completed in code except live Google smoke.

---

## No runtime / schema changes

No migrations. No new routes. No production behavior change. `integration_accounts` still 0. Workers/schedule unchanged.

---

## Next milestone

M20 — Owner combined Google live smoke / hardening when ready. Then M21 GitHub or M22 Workspace per Owner priority. Do not start Tauri/Flutter/Orb/GitHub OAuth in this repo until those milestones.
