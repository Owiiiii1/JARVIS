# Cursor Work Report — M22.1 Multimodal images + copyable artifacts

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**GitHub:** `Owiiiii1/JARVIS` (`origin/main`)  
**Public URL:** https://jarvis.owlsolutions.net

Status: **IMPLEMENTED / NOT VALIDATED**. Owner deferred automated tests. No live AI vision, Google, or GitHub calls.

---

## Scope

Owner Workspace can attach PNG/JPEG/WebP (picker, drag/drop, Ctrl/Cmd+V screenshot), send text+images as one user turn through the existing Conversation Engine, and copy assistant artifacts in one click.

No second chat engine. No Telegram photo ingestion. No public attachment URLs. No attachment memory pipeline.

---

## Backend

- `config/chat_attachments.php` is the only limit source (max 5 images, 10 MB each, 25 MB total, MIME allow-list, thumbnail size, retention TBD).
- Migration `message_attachments`: generic `kind`, private `storage_disk`/`storage_path`, sanitized name, mime, size, dimensions, sha256, bounded metadata. No bytes in DB.
- Multipart `POST /jarvis/chats/{id}/messages` with `body`, `client_message_id`, `images[]`. Empty body allowed iff ≥1 image.
- `public/.user.ini` raises PHP `upload_max_filesize`/`post_max_size` to 32M for this app (nginx already allows 64M). App limits remain in `config/chat_attachments.php`.
- If storage succeeds and DB fails, pending files are deleted. If AI fails, the user message and attachments remain.
- `AiContentPart` + `AiChatMessage.contentParts`. Current inbound images only. Historical images are text placeholders. `supportsVision()` on the provider client: Gemini true; OpenAI/Anthropic false → user-safe `vision_not_supported`.
- Gemini adapter maps image parts to `inlineData` internally. Conversation Engine does not speak Gemini JSON.

## Frontend

- Workspace composer: paperclip, drop, paste-image without hijacking text paste, thumbnail strip, remove-before-send, limit errors from server props.
- History thumbnails + lightbox.
- SafeMarkdown: fenced code stays a code block; ` ```artifact Title ` is a distinct Artifact block. Copy is raw text.

## Verification (allowed)

- `php artisan migrate` for `message_attachments`
- `php artisan route:list` for attachment routes
- `vendor/bin/pint --dirty`
- `npm run build`
- **TESTS NOT RUN**
- **NO LIVE VISION / Google / GitHub**

## Next

**M23 Voice Runtime Foundation.**
