# Where Are We — Uploadiny

## 2026-04-22 — DB removed, filesystem is the only source of truth

### Session summary

Stripped the database out of the app. Uploads are now stored as
`<uuid>__<sanitized-original-name>.<ext>` directly on the `public`
disk. No migrations, no models, no SQLite. Session, cache, and queue
use `file` / `file` / `sync` drivers.

### Files changed

- `app/Http/Controllers/UploadController.php` — rewritten to read/write
  the filesystem directly. Keeps the same five endpoints (index, store,
  show, destroy, destroyAll).
- `.env` and `.env.example` — removed DB vars; switched session/cache
  to file, queue to sync.
- `phpunit.xml` — removed `DB_CONNECTION` / `DB_DATABASE` test env.

### Files deleted

- `database/database.sqlite`
- `database/migrations/*` (all four skeleton + uploads migrations)
- `app/Models/Upload.php`
- `app/Models/User.php`
- `database/factories/UserFactory.php`
- `database/seeders/DatabaseSeeder.php`
- `app/Models/` directory (now empty, removed)

### Current state

- Laravel 12 / PHP 8.4+. No database. All metadata encoded in the
  stored filename.
- End-to-end verified via a tinker-equivalent smoke script:
  store / show / listUploads / destroy / destroyAll / 404 on unknown
  UUID / 422 on blocked extension — all pass.
- Existing feature test (`tests/Feature/ExampleTest.php`, hits `/`
  and expects 200) still passes.
- Sanitization confirmed: `my photo (édition spéciale) !.png` →
  `my-photo-edition-speciale.png`.
- The Blade view (`resources/views/upload.blade.php`) needed no
  changes — the controller now returns a `Collection` of `stdClass`
  objects with the same property names the view already used
  (`uuid`, `original_name`, `size`, `mime_type`, `path`).

### Docs updated this session

- `~/docs/upload/00-context/architecture.md` — topology no longer
  shows a metadata store; new storage-layer section describes the
  filename convention and sanitization.
- `~/docs/upload/02-features/upload-flow/design.md` — every endpoint
  description rewritten to describe filesystem lookups; delete-all
  simplified.
- `~/docs/upload/03-logs/decisions.md` — added the "Remove the
  database entirely" decision entry for 2026-04-22, with rationale and
  trade-offs.

### Pending / suggested next steps

- No auto-cleanup policy exists. If disk fills up, a scheduled prune
  (e.g. "delete files older than 30 days") is the natural next
  feature. Requires Bruno's call.
- Feature tests for the upload flow would be cheap to add now that
  the whole flow is file-based (no DB setup). None added this session.

### Key decisions (this session)

- Flat filename convention (`<uuid>__<name>.ext`) rather than
  per-upload subdirectory. Simpler listing, simpler delete.
- Sanitize filenames to ASCII safe-set. The UUID prefix is always a
  clean v4, so lookup is never ambiguous even if the user name
  collapses to the fallback `file`.
- MIME is detected via `mime_content_type()` on the full path rather
  than via the `Storage` facade (avoids a static-analysis false
  positive and has no runtime downside).
- Kept `UploadController` as a single class with small private
  helpers; did not extract a service or repository. Product is one
  feature, scaffolding would be premature.


## 2026-10-03 — Project feedback MVP (local implementation)

- Replaced the unstructured public upload screen with a private, authenticated Laravel/Tailwind workspace. No public registration; the personal account is provisioned by `php artisan uploadiny:account`.
- Website/backend remain at the repository root; Expo app, native Share Extension, and build tooling remain under `mobile/`.
- SQLite stores projects, upload chunks, image feedback, and description jobs. Originals and annotated PNGs are stored on the private local disk. Every image route requires a browser session or API bearer credential.
- Projects support create/edit/delete. Images can move between projects while retaining their original chunk and feedback. Project deletion removes current project images, feedback, and files; moved images survive deletion of their former project.
- One upload action is one chunk, including a single image. Website and Share Extension start a draft, append images individually, and publish only when the full expected group arrives. Incomplete groups never replace the latest chunk. The latest project chunk includes only images currently in that project.
- The editor supports freehand drawing, arrows, boxes, undo/redo, and written feedback. Saved annotated PNGs and normalized drawing data accompany original images in the agent response. Feedback revisions reject stale concurrent edits.
- OpenAI vision description jobs run through the database queue. Pending/failed descriptions do not block feedback or retrieval. A retry action is available. Live local verification passed with the supplied project credential: two uploaded images received non-empty GPT-4.1 nano descriptions through the database queue, and the authenticated latest-chunk API returned them. The credential is stored only in the private local .env; deployment configuration remains pending.
- New shared dynamic command: `~/Herd/.dynamic-commands/uploadiny.md`. It retrieves the selected project's latest chunk through authenticated API calls and preserves source feedback. The old screenshot command is not part of this workflow.
- Existing local uploads were authorized for deletion; the old local directory contained no regular uploads. No remote production files were touched.
- Native endpoint targets `https://uploadiny.com/api`. iPhone sharing selects a project, uploads multiple images as one chunk, and retains success until tapped. HEIC/HEIF/TIFF Share Sheet representations are converted to JPEG before transfer. Browser uploads support JPEG, PNG, WebP, GIF, and BMP; HEIC photos should use iPhone sharing or be exported to JPEG first.
- Deployed to https://uploadiny.com with proxied Cloudflare DNS, strict origin TLS, an isolated application directory, and a persistent Supervisor database-queue worker matching the PHP-FPM application user. Production API smoke passed: private login, project CRUD, exact chunk completion, latest chunk, original download, feedback, image move, and live GPT-4.1 nano descriptions. Temporary smoke data was removed. iPhone build 4 is signed, installed, launched, and independently verified running. Browser/E2E and physical Share Sheet interaction acceptance remain Bruno-owned.

- Final checks: 23 PHPUnit tests / 320 assertions, production Vite build, live local authenticated HTTP flow, Swift type checking, and iOS simulator build. No browser visual acceptance or physical-iPhone acceptance claimed.
- Abandoned draft uploads are discarded after 24 hours by a delayed database-queue job; published chunks are never discarded by this cleanup. Client cancellation attempts immediate cleanup. Comment-only edits retain unchanged drawings even when preview is unavailable.

- iOS 27 launch compatibility: reused Taxiny's scene lifecycle config plugin without upgrading dependencies. Build 4 declares a scene manifest, creates its window from the connected scene, and preserves URL/background forwarding. Three plugin regression checks and mobile TypeScript pass. Shared `do uploadiny` now defaults to the verified production API.


## 2026-10-04 — 0.1.0 first versioned public source release

- The complete Laravel website/API and `mobile/` iPhone source are prepared as
  the first public source release at `brunocfalcao/uploadiny`.
- The browser editor now has direct regression coverage for Cmd/Ctrl drawing
  undo/redo, editable and modal exclusion, and keyboard project-picker
  navigation, typeahead, Escape, and programmatic-selection synchronization.
- PHPStan level 6, PHP Insights metrics, Composer's locked advisory scan,
  PHPUnit, JavaScript regression tests, and the production Vite build are
  configured for the release source. PHPMD is not enforced on PHP 8.5 because
  its current release contaminates its own report stream with vendor
  deprecations.
- The iPhone release build advances from build 4 to build 5. The tracked Share
  Extension configuration remains the authority for its production API URL.
