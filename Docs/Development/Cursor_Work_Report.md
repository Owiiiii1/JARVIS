# Workspace Presentation Polish + Scenario 8 Fix

## Starting HEAD

`bc949ea57cfae53d64467d672431f115a06f7f28`, equal to `origin/main`, working tree clean.
Branch `main`. No dependency changes; no migrations.

## Owner live findings

Scenario 8 of the Core Daily Workflow campaign came back **LIVE FAIL**. After Scenario 7 closed
the build task and its subtask, cancelled the linked reminder and resolved the one-shot watcher,
chat synthesis answered correctly — but **Обзор** still showed:

- «Сегодня и ближайшее»: «Проверить задачу «Проверить авторизацию»», «Задача #254 все еще открыта»
- «Нужно внимание»: «Depends on Задача #253», «Explicit depends_on relationship is still active»
- «Жду»: «Задача #254 все еще открыта», «One-shot watcher still waiting for its event»
- «Что изменилось»: the same completion twice

A second class of problems came with it: the Workspace was speaking backend. Enum names
(`task_state`, `overdue_by`, `depends_on`, `works_on`, `knowledge_linked`, `task_completed`),
`healthy`, task ids, timezone names, delivery adapters and cooldown seconds were all on screen,
in three panels that looked like three different products.

## Root cause of stale Overview

Not one bug — five, all of them the same mistake in different places: a derived slice trusted
the evidence instead of the domain.

1. **Knowledge relations outlived the work.** `ProjectAttentionResolver` accepted a `depends_on`
   relationship as a live blocker whenever the *relationship* was active. The relationship is
   history; whether it still blocks anything is a fact about the task table.
2. **Watchers were matched on one spelling of their task.** `WaitingForResolver::staleWatcherIds()`
   read only the `task_id` column, so a watcher carrying its task in `source_config.task_id`
   (which is how the chat-created watcher on #254 was stored) was never recognised as resolved.
   `WatcherEvaluationDispatcher::afterTaskChanged()` had the same blind spot, so that watcher was
   never even re-evaluated when the task closed and stayed `Active` forever.
3. **The agenda was not filtered against canonical state.** `upcoming()` emitted reminders and
   tasks without asking whether the linked task was still open, and emitted both a task and its
   own reminder as two separate things to do.
4. **One completion had two fingerprints.** The canonical task row produced `task:{id}` and the
   knowledge `task_completed` event produced `fp:{source_fingerprint}`, so deduplication had
   nothing to match on and the change feed printed the completion twice.
5. **A completed parent hid its open subtask.** `panelFor()` listed only `parent_task_id IS NULL`
   rows. Force-completing a parent (the browser-`confirm` path) left the subtask open and
   invisible in every active list — which is exactly what made the Owner's Overview look "stale"
   when part of it was reporting genuinely open work in an unreadable way.

## Backend fixes

- **`CanonicalStateResolver`** (new): maps a knowledge entity back to its canonical task, via
  `metadata.task_id`, via `knowledge_entity_sources.task_id`, or via the entity name — including
  names with the id written into them («Задача #253: …»), where the id still has to agree with
  the canonical title before it is trusted. Also resolves a watcher's task from either the column
  or the source config, and returns the canonical title for any entity label. Bounded queries,
  memoized per FactPack, user-scoped.
- `FactPack` carries `userId`, so canonical lookups stay inside the owner's rows.
- `WatcherEvaluationDispatcher::afterTaskChanged()` now matches `task_id` **or**
  `source_config->task_id`, so a chat-created watcher is re-evaluated and one-shot watchers whose
  condition requires an open task are resolved with `cursor.resolved_reason = task_closed`.
- `TaskService::panelFor()` / `activeOpenCount()` treat a subtask whose parent is closed as
  top-level work, and the row carries «Подзадача задачи «…»» for context.
- Knowledge evidence is untouched. Nothing is deleted, no relationship is deactivated to make a
  slice behave. Provenance stays; only the derived reading of it changed.

## Synthesis fixes

- `WaitingForResolver`: skips watchers whose canonical task is closed; skips `waiting_on`
  relations whose target entity resolves to a closed task; marks external waits explicitly with
  `extra.external` instead of leaving downstream code to sniff strings.
- `ProjectAttentionResolver`: a `depends_on` relationship is a blocker only while **both** sides
  are open; `external` is read from the flag, not from the title.
- `SynthesisChangeAssembler::upcoming()`: drops reminders whose task is closed, drops closed
  tasks, drops a task that already has a reminder on the agenda, and drops watchers that can no
  longer fire.
- `SynthesisChangeAssembler::recent()`: canonical task changes and their knowledge events share
  `task-change:{task}:{status}`.

## Dedupe fixes

- One fingerprint per semantic event across the task table and Knowledge (above).
- `SynthesisDeduplicator::changes()` adds a second pass over the change feed, dropping a repeated
  **sentence** even when two rows came from different evidence — that is what collapsed the
  duplicated «Marco works_on YFS».
- That sentence pass is deliberately limited to `kind === 'change'`. On the agenda two rows may
  legitimately share a title and differ by time, and collapsing them would lose information; the
  agenda relies on fingerprints plus the reminder/task suppression instead.

## Presentation architecture

`app/Services/Workspace/Presentation/` — five small deterministic helpers, no AI call, no second
synthesis engine, nothing stored in the database:

| Helper | Responsibility |
| --- | --- |
| `HumanMoment` | «Сегодня, 18:00», «Завтра, 11:00», «6 сент., 18:30», «2 дня», Russian plurals |
| `HumanStatusLabel` | statuses, priority, subtask progress, reminder/watcher problem lines |
| `HumanWatcherDescription` | a watcher as one sentence: condition + reaction |
| `HumanRelationLabel` | a knowledge edge as a sentence about two named things |
| `HumanSynthesisText` | wording for derived synthesis items; strips ids written into names |

A helper returns `null` when the honest answer is silence, and the card omits the line. The
serializers (`TaskService`, `ReminderService`, `WatcherService`) and every synthesis resolver
call them, so the panels, the synthesis tool results and the Conversation AI all read the same
sentences. Machine fields still travel in the payload for Core; the UI renders the human field.
Contract written up in `Docs/WORKSPACE_PRESENTATION.md`.

## Tasks redesign

Shared primitives under `resources/js/personal-workspace/components/`: `PanelShell`,
`PanelSection`, `WorkspaceCard`, `OverflowMenu`, `ConfirmDialog`, `ChoiceDialog`. Same radius,
padding, title hierarchy, empty/loading/error treatment in all four panels; no new dependency,
existing React/Tailwind/lucide stack, Escape and outside-click on menus and dialogs.

Task card: title, schedule («Завтра, 18:00» / «Без срока»), then only meaningful meta — project,
«Высокий приоритет» / «Срочно» (never normal or low), «В работе», subtask progress, parent
context. No «Открыта» on an active card, no reminder/subtask counters, no source conversation
line, no five text buttons. Primary action **Выполнить**; ⋯ carries Начать работу · Изменить ·
Добавить подзадачу · Отменить задачу, and «Вернуть в работу» for a completed task through the
`tasks.reopen` endpoint that already existed. Only applicable actions are rendered, from the
backend's own capability flags.

## Subtasks UX

The panel payload now carries bounded subtask rows (title, human status, due label and per-row
action flags), so the parent card owns its checklist. Progress reads «1 из 2 подзадач
выполнено»; the list expands on demand and a task with no subtasks has no expander at all.
Completed subtasks are muted and struck through, open ones can be completed inline or edited
from their own ⋯. One level deep, strictly owner-scoped, no second task API.

Completing a parent with open subtasks no longer uses a browser `confirm`. A `ConfirmDialog`
names what is still open and offers **Выполнить всё** / **Вернуться**; «Выполнить всё» closes
the subtasks first and then the parent, so the Scenario 7 state — a completed parent with a live
subtask — is not reachable from the UI. Where that state already exists in data, the subtask is
listed on its own instead of disappearing.

## Reminders redesign

Card: what to do, then when. «По задаче «…»» only when it is attached to a task. In a normal
state nothing about timezone, channel, delivery state or «Запланировано». Problems do speak:
«Не удалось доставить», «Нет активного канала уведомлений», and a timezone line only when the
reminder's zone differs from the user's. Primary **Выполнено** appears only once due or
delivered; ⋯ carries Изменить · Отложить · Отметить выполненным · Отменить напоминание, with
«Отложить» opening one choice list (10 минут · 1 час · Завтра · Выбрать время) instead of four
buttons on the card.

## Watchers redesign

A watcher reads as the agreement it encodes — «Если «Проверить авторизацию» просрочится больше
чем на сутки, я сообщу вам.» — with a secondary line only for state worth reading («Ждёт
события», «Приостановлено», «Нужно переподключить Gmail», «Сработало 6 сент., 18:30»). Healthy
health is never printed; trigger, condition and reaction enums never reach the screen. ⋯ offers
Приостановить/Возобновить and Отменить, from the backend flags. No edit feature was invented.
«Создать через чат» is the primary creation path; the manual DSL form is preserved behind
«Расширенные настройки». Watcher serialization takes a timezone, so tool results and the panel
agree on wording.

## Overview redesign

Sections unchanged (Сегодня и ближайшее · Нужно внимание · Жду · Что изменилось · Открытая
работа). Every row is now a sentence with an optional reason line, and no raw type is printed
under a card. React keys use an opaque hash of the fingerprint rather than an internal id.

| Before | After |
| --- | --- |
| `Completed: Проверить новый билд YFS` + `task_completed` | Задача «Проверить новый билд YFS» выполнена |
| `Задача #254 все еще открыта` | Ждём выполнения «Проверить авторизацию» |
| `One-shot watcher still waiting for its event.` | Если «Проверить авторизацию» просрочится больше чем на сутки, я сообщу вам. |
| `Depends on Задача #253` + `Explicit depends_on relationship is still active` | Работа зависит от завершения «Проверить новый билд YFS» — and only while it is genuinely open |
| `Marco works_on YFS` + `knowledge_linked` | Marco работает над YFS |

Read-only diagnostic against the live Owner data after the change, for the record:

```
== upcoming ==
 - Написать и отправить коммерческое предложение стоматологической клинике | Завтра, 11:00
 - Проверить задачу «Проверить авторизацию» | Завтра, 11:00
== waiting_for ==
 - Ждём выполнения «Проверить авторизацию» | Если «Проверить авторизацию» просрочится больше
   чем на сутки, я сообщу вам.
== recent_changes ==
 - Задача «Проверить новый билд YFS» выполнена
 - Marco обещал прислать новый дизайн до пятницы
 - Marco работает над YFS
 - «Проверить авторизацию» зависит от «Проверить новый билд YFS»
```

No enum names, no ids, no duplicated completion. Task #254 is genuinely still open in the live
data — the Owner's Scenario 7 force-completed its parent — and it now appears as open work
labelled «Подзадача задачи «Проверить новый билд YFS»» instead of as an unexplained stale row.

One piece of pre-existing data noise surfaced during the diagnostic: knowledge event `#627`
stores a corrupted title, «Внесена информация об ответcanonical за дизайн YFS». It predates this
work, presentation renders stored text verbatim, and production data was not modified.

## Natural response changes

The synthesis facts are humanized before the Conversation AI sees them, so the model is handed
«Ждём выполнения «Проверить авторизацию». Если задача останется открытой, я сообщу вам.» rather
than an instruction mentioning watcher #254 and `overdue_by`. Structured fields still ride along
in the tool result for machine use. The deterministic fallback summary was reworded the same way.
No second AI pass, no rewrite call.

## Ownership / isolation

Unchanged and re-checked at every new read: canonical task lookups are filtered by
`user_id`, the resolver only accepts a task whose owner matches the FactPack user, subtask rows
are filtered by owner before serialization, watcher `linked` names come from owner-scoped
relations, and the panels call the same owner-guarded endpoints as before. No new route, no new
capability, no cross-user field added to any payload.

## Files changed

New:

```
app/Services/Synthesis/CanonicalStateResolver.php
app/Services/Workspace/Presentation/HumanMoment.php
app/Services/Workspace/Presentation/HumanRelationLabel.php
app/Services/Workspace/Presentation/HumanStatusLabel.php
app/Services/Workspace/Presentation/HumanSynthesisText.php
app/Services/Workspace/Presentation/HumanWatcherDescription.php
resources/js/personal-workspace/components/ChoiceDialog.jsx
resources/js/personal-workspace/components/ConfirmDialog.jsx
resources/js/personal-workspace/components/OverflowMenu.jsx
resources/js/personal-workspace/components/PanelSection.jsx
resources/js/personal-workspace/components/PanelShell.jsx
resources/js/personal-workspace/components/WorkspaceCard.jsx
tests/Unit/Workspace/HumanPresentationTest.php
Docs/WORKSPACE_PRESENTATION.md
```

Modified:

```
app/Http/Controllers/Jarvis/JarvisWatcherController.php
app/Services/Reminders/ReminderService.php
app/Services/Synthesis/CommitmentResolver.php
app/Services/Synthesis/CrossSourceSynthesisService.php
app/Services/Synthesis/DTO/FactPack.php
app/Services/Synthesis/DTO/SynthesisItem.php
app/Services/Synthesis/ProjectAttentionResolver.php
app/Services/Synthesis/SynthesisChangeAssembler.php
app/Services/Synthesis/SynthesisDeduplicator.php
app/Services/Synthesis/SynthesisFactCollector.php
app/Services/Synthesis/WaitingForResolver.php
app/Services/Tasks/TaskService.php
app/Services/Tools/Watchers/GetWatcherTool.php
app/Services/Tools/Watchers/ListWatchersTool.php
app/Services/Watchers/WatcherEvaluationDispatcher.php
app/Services/Watchers/WatcherService.php
resources/js/personal-workspace/OverviewPanel.jsx
resources/js/personal-workspace/RemindersPanel.jsx
resources/js/personal-workspace/TasksPanel.jsx
resources/js/personal-workspace/WatchersPanel.jsx
tests/Feature/CrossSourceSynthesisTest.php
Docs/CURRENT_STATE.md
Docs/VALIDATION_CORE_WORKFLOW.md
```

## Tests authored but NOT executed

**AUTHORED / NOT EXECUTED.** No `phpunit`, no `php artisan test`, no Pest, no targeted run, no
`migrate:fresh`, no truncate — this server has no guaranteed separate test database.

New:

- `tests/Unit/Workspace/HumanPresentationTest.php` — relative day/time wording, Russian plurals
  («1 час» / «2 часа» / «5 часов» / «сутки» / «2 дня»), silence for normal priority and open
  status, subtask progress wording, id stripping from knowledge names, relation sentences.
- `tests/Feature/CrossSourceSynthesisTest.php::test_completed_work_disappears_from_every_derived_slice_and_reads_as_human_text`
  — the Scenario 8 regression: parent + subtask + linked reminder + one-shot watcher +
  `depends_on` evidence, all completed, then asserts the reminder is cancelled, the watcher is no
  longer active, nothing closed appears in upcoming / waiting / open loops / open work / blockers
  / attention, the completion appears exactly once, and no enum name or id appears in any
  user-facing string.
- `tests/Feature/CrossSourceSynthesisTest.php::test_open_subtask_of_a_closed_parent_stays_visible_in_the_task_panel`
  — the orphaned subtask is listed with its parent label and schedule, the parent shows progress
  and its subtask rows, and `active_count` counts the open work.

Updated (existing assertions that named the old technical strings):

- waiting-for now asserts the work is named rather than the watcher name, and the closed-task
  case asserts by watcher source reference so it cannot pass vacuously;
- change-feed assertions match the humanized commit and watcher wording.

## Static checks

| Check | Result |
| --- | --- |
| `php -l` on every touched PHP file | clean |
| `vendor/bin/pint --dirty --format agent` | clean after fixes applied |
| `composer validate` | see run log below |
| `npm run build` | built, only the pre-existing chunk-size warning |
| `git diff --check` | clean |
| `php artisan route:list` | unchanged; task/reminder/watcher/synthesis routes intact |
| `php artisan migrate:status` | unchanged; no migrations added |
| Read-only synthesis + panel diagnostics | verified the wording and the filtering above |

## Scenario 8 revalidation instructions

`Docs/VALIDATION_CORE_WORKFLOW.md` keeps Scenario 8 as **LIVE BUG / READY FOR REVALIDATION** and
adds **Scenario 8.1** with the exact recheck: what Обзор must not show (closed subtask as open /
upcoming / waiting, resolved watcher waiting, cancelled reminder on the agenda, an active
dependency against the completed parent, any duplicate), what it must show (the completion once
as a sentence, Marco's commitment while open, the dentistry item once), the presentation checks
(no enums, no health string, no ids, condition sentences, no browser `confirm`) and the subtask
visibility check. Cursor did not mark anything PASS.

## Deferred validation

Scenarios 9–10 are paused at the Owner's instruction and marked `PAUSED` in the campaign matrix.
Everything already in `Docs/DEFERRED_VALIDATION.md` stays deferred: live Google/GitHub campaign,
Telegram sends, ElevenLabs/Gemini smoke, DST and recurrence edge cases, A/B IDOR campaign. The
desktop client remains CANCELLED; this work is Web Workspace only.

## Production safety

No migration, no schema change, no dependency change, no new route, no new capability, no queue
or scheduler change. No production data was written: every live check was a read-only diagnostic
or a serializer call. No provider was contacted — no Gmail, Calendar, GitHub, Telegram,
ElevenLabs or Gemini call was made. No fake-user campaign was run. Presentation strings are
computed at serialization time, so nothing needs backfilling and a rollback is a code revert
plus `npm run build`.
