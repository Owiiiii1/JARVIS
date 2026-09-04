# Cursor Work Report — M22.2 Persistent Storage + Ephemeral Screenshots + Workspace UX

Date: 2026-09-04  
Host: `/var/www/jarvis`  
Public URL: https://jarvis.owlsolutions.net  
GitHub: Owiiiii1/JARVIS  
Branch: `main`

Status: **IMPLEMENTED / NOT VALIDATED**. Owner deferred all automated/live tests. No live AI vision, live conversation send, Google, or GitHub calls during this work.

---

## Before HEAD

Task brief named:

`6161e04788e6fdfb7d3cfa37d5ddc27ee6ab60fd`  
`feat: add workspace image attachments and copyable artifacts`

Actual `origin/main` tip before this commit (includes two JPEG hotfixes after M22.1):

`ac85b14759efae52cf79dbe8581fa25923a87991`  
`fix: identify chat images by pixels, not Windows mime labels`

M22.1 was already live. Conversation Engine was not rewritten.

---

## DB backup

Before migrate, dump of production tables (gitignored, not in repo):

`storage/backups/m22_2_20260904_154225.sql` (~62 KB)

Tables: `users`, `conversations`, `messages`, `message_attachments`.

No secrets dumped into git.

---

## Migrations / batch

Additive only. `php artisan migrate --force`.

Batch **16**:

- `2026_09_04_160000_add_lifecycle_to_message_attachments_table`
- `2026_09_04_160100_create_stored_file_tables`

No destructive schema change. No mass purge during migration.

Safe production counts after migrate (no contents):

- `message_attachments`: 1 row (existing M22.1 image)
- `stored_files`: 0
- `stored_file_chunks`: 0
- `message_stored_files`: 0

Existing image row backfill: `retention_class=ephemeral`, `summary_status=pending`, `purged_at=null`, `expires_at` from `created_at + retention_hours`. Original bytes left in place.

---

## Message attachment lifecycle

New columns: `retention_class`, `expires_at`, `summary_status`, `summary_text`, `summarized_at`, `purged_at`, `purge_failure_count`.

`message_attachments` remains the Core entity for ephemeral chat media.

---

## Screenshot retention config

`config/chat_attachments.php`:

- `retention_class` default `ephemeral` (not hardcoded image==ephemeral in every layer)
- `retention_hours` default **24**
- `hard_retention_days` default **7**
- `purge_batch` 50
- `summary_max_chars` 1200
- `summary_queue` `memory`
- `summary_max_attempts` 3

---

## Summary pipeline

Successful persisted user image → `SummarizeMessageAttachmentJob` (`memory` queue) → `AttachmentVisionSummaryService`.

Uses `AiChatGateway` + Owner Conversation configuration (production vision-capable Gemini). Does **not** hardcode Gemini HTTP. Does **not** silently change Owner Analysis AI.

Dedicated `summary_text` is derived attachment metadata, not the assistant reply and not personal memory. Memory analysis prompt now forbids bulk-ingest of screenshot summaries / Storage contents.

Hourly command also enqueues a bounded batch of pending summaries (for existing M22.1 rows).

---

## Purge schedule / hard fallback

Command: `jarvis:attachments:purge-ephemeral`  
Schedule: hourly, `withoutOverlapping`. Reminders everyMinute left untouched.

Eligibility:

- ready summary **and** `expires_at <= now`, **or**
- `created_at` older than 7 days (hard fallback even if summary failed)

Bounded batch. Deletes original + thumbnail, clears paths, sets `purged_at`, keeps DB row + summary. After hard fallback without summary: metadata indicates summary unavailable.

Existing production screenshot was **not** deleted during implementation.

---

## Persistent Storage schema

- `stored_files` — metadata only, private disk bytes
- `stored_file_chunks` — unique `(stored_file_id, chunk_index)`
- `message_stored_files` — optional pivot, unique pair

Config: `config/jarvis_storage.php`  
Disk: Laravel `local` (`storage/app/private`) under `jarvis-storage/{user}/{uuid}/…`

No automatic expiry. Remain until owner deletes.

---

## Text formats / limits

App-level: 20 MB per file, 8 files/upload, 40 MB total. Extracted text capped (2M chars). Chunk ~8k with 400 overlap. Inline current-turn text only if ≤ 4k chars.

Supported: listed text/source extensions in config. Rejected: PDF/Office/ZIP/executables/images as Storage, null-byte binaries.

MIME + extension + binary heuristics. HTML/XML stored as documents; download always `attachment`.

---

## Extraction / chunking / search

- `StoredFileTextExtractor` — BOM, line endings, UTF-8 when safe
- `StoredFileChunker` — line-aware windows
- `StoredFileSearchService` — SQL `LIKE` on names/summaries and chunk text. No embeddings. No FULLTEXT in M22.2.

Queued `ProcessStoredFileJob` (`default`); sync for small files (`sync_process_max_bytes`). Failed processing: `status=failed`, original kept.

sha256 stored. No silent merge of intentional duplicate uploads. `client_upload_id` for retry idempotency.

---

## Tools / capability

Capability: `UserCapability::STORAGE` (owner has `*`; regular users do not get Storage tools/UI).

Registered:

- `list_storage_files`
- `search_storage_files`
- `get_storage_file`
- `search_storage_file_contents`
- `read_storage_file_chunks`
- `delete_storage_file` (destructive → `ToolConfirmationService`)

Hard bounds: result count, excerpt chars, total tool chars, `truncated=true`. Storage is never auto-injected into every prompt. Current-turn attached files expose `public_id` for tools.

---

## Chat attachment integration

Composer: images + persistent text files in one turn. `images[]` remain ephemeral `message_attachments`. `files[]` create `StoredFile` + pivot. Direct `/jarvis/storage` upload does not create a conversation/message.

UI: “Temporary image · expires in ~24h” vs “Saved to Storage”. Purged screenshots: expired card + bounded summary, no broken image. Historical context uses screenshot summary placeholders.

---

## `/jarvis/storage` routes/UI

Owner Workspace (not Admin):

- `GET/POST /jarvis/storage`
- `GET/PATCH/DELETE /jarvis/storage/{public_id}`
- `GET /jarvis/storage/{public_id}/download`

Sidebar **Storage** nav. Paginated list, drag/drop upload, search, rename, delete confirmation modal, bounded preview, source-chat link.

---

## Focus fix

Stable textarea ref. `focusComposer({ forceDesktopOnly: true })` via `(hover: hover) and (pointer: fine)`. Restores after send/error/chip/remove-attachment/lightbox once textarea is enabled. Mobile does not force the keyboard after a turn. No pointless `setTimeout`.

---

## Ownership / security

StoredFile and attachment routes check `user_id`. UUID alone is insufficient. Untrusted-data guidance in conversation system context. Logs: ids, type, size, chunk count, status/error class — not contents, summaries, or absolute paths.

---

## Build / static verification

Ran:

- `php artisan migrate --force` (batch 16)
- `php artisan migrate:status`
- `php artisan route:list --name=jarvis` (17 routes including Storage)
- `php artisan schedule:list` (reminders everyMinute; purge hourly; memory,default queue:work stop-when-empty everyMinute)
- `php artisan queue:failed` (none)
- `npm run build` (success)
- `vendor/bin/pint --dirty`
- `composer dump-autoload`

**TESTS NOT RUN — Owner deferred.**  
**NO LIVE AI / VISION / Google / GitHub.**

Do not claim validated.

---

## Known issues

- Existing M22.1 screenshot is `summary_status=pending` until the hourly enqueue + `memory` worker runs. Original bytes were not purged.
- Storage search is substring `LIKE`, not FTS/vectors.
- Telegram photos/documents still not ingested.
- ContextBudgetManager not implemented (M22.3).
- No PDF/Office/ZIP/persistent-image Storage.

---

## Next

**M22.3 — Web Research + Context Budget Manager.**
