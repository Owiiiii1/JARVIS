# Deferred Validation Backlog

Owner postponed live validation campaigns. These items are **IMPLEMENTED / NOT VALIDATED** (or prepared, not executed). They are **not** MANUAL PASS. They do **not** block further development.

Runtime snapshot: [CURRENT_STATE.md](CURRENT_STATE.md). Plan: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md).

| Item | Code status | Live validation |
| --- | --- | --- |
| Phase C.1 Conversation Intelligence | IMPLEMENTED | Deferred |
| Phase C.2 ElevenLabs Realtime («Диалог Beta») | IMPLEMENTED (feature-flagged) | Deferred |
| Google Calendar / Gmail campaign | IMPLEMENTED | Deferred |
| GitHub OAuth + tools campaign | IMPLEMENTED | Deferred |
| Onboarding full E2E (completion / profile update) | IMPLEMENTED; entry MANUAL PARTIAL | Deferred |
| Telegram Voice Input (DM voice → Gemini STT → Core) | IMPLEMENTED | Deferred |
| A/B isolation campaign | Prepared | Not executed |
| Recurrence / DST / multi-device reminder edge cases | Recurrence IMPLEMENTED; core reminder flow MANUAL PASS | Deferred exhaustive edges |
| Phase B.2 Tasks / Notification Center / briefs / proactive | IMPLEMENTED | Deferred |
| Telegram Groups analysis (Owner live) | IMPLEMENTED | Deferred |
| Tavily / `fetch_web_page` as a distinct Owner check | IMPLEMENTED | Deferred |
| Screenshot ephemeral purge as a distinct Owner check | IMPLEMENTED | Deferred |
| Destructive Storage delete | IMPLEMENTED | Deferred |
| Core Reliability historical retry/prune | IMPLEMENTED / CLASSIFIED | Owner decides later; not run |
| Phase E.1 Knowledge Layer | IMPLEMENTED | Deferred |

Telegram Voice **Replies** remain MANUAL PASS. Web **Рация** pipeline remains MANUAL PASS. Desktop remains CANCELLED.

When a campaign is run, record the result in CURRENT_STATE. Do not mark MANUAL PASS from code-only work.
