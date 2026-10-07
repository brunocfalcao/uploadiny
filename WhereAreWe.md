# Where Are We — Uploadiny

## 2026-10-08 — v0.11.1 candidate, not deployed

- Candidate iOS metadata: 0.11.1 build 27; no mobile behavior change.
- Website: an upload opens on its first file the first time and on the last
  viewed file afterwards, remembered per browser (500 most recent uploads).
  Covered by new `chunk-memory` tests. No migration, dependency or environment
  change.

## 2026-10-08 — v0.11.0 deployed via FAST

- Shipped `4f18a91bea` on 2026-10-07 at 22:12:04 UTC in 107 seconds. The
  completed receipt records website/API verification and signed iPhone 0.11.0
  build 26 installation and launch.

- Candidate iOS metadata: 0.11.0 build 26; no mobile behavior change.
- Website editor: copy or move a file into a brand-new upload chunk in the
  current project. The chunk picker shows each upload's latest-file time.
  Covered by a new `WorkspaceTest` case. No migration, dependency or
  environment change.

## 2026-10-07 — v0.10.2 deployed via FAST

- Shipped `9de038d589` on 2026-10-07 at 21:25:29 UTC in 93 seconds. The
  completed receipt records website/API verification and signed iPhone 0.10.2
  build 25 installation and launch.

- Candidate iOS metadata: 0.10.2 build 25; no mobile behavior change.
- Website: upload cards show the time the latest file joined, in the viewer's
  timezone (was the first file's time, in UTC). Escape in the editor returns
  to the project screen when nothing is selected and no field has focus.
- Dependencies (development only): `larastan/larastan` v3.12.3 → v3.13.0,
  `laravel/boost` v2.10.2 → v2.10.3, `symfony/polyfill-deepclone` v1.43.0 →
  v1.43.1. No migration or environment change.

## 2026-10-07 — v0.10.1 deployed via FAST

- Shipped `830b7294d7` on 2026-10-07 at 09:02:46 UTC in 108 seconds. The
  completed receipt records website/API verification and signed iPhone 0.10.1
  build 24 installation and launch.

- Candidate iOS metadata: 0.10.1 build 24; no mobile behavior change.
- Website editor: deleting a file keeps the editor open on the next file in
  the same upload (previous one when the deleted file was last) and drops the
  chunk counter by one; the project screen is only reached when the upload has
  no files left. Covered by two new `workspace-editor` tests.
- Dependency: `laravel/prompts` v0.3.24 → v0.3.25 (runtime vendor, console
  only). No migration or environment change.

## 2026-10-07 — v0.10.0 deployed via FAST

- Shipped `d3e9129e45` on 2026-10-06 at 22:41:03 UTC in 298 seconds. The
  completed receipt records website/API verification and signed iPhone 0.10.0
  build 23 installation and launch.
- Candidate iOS metadata: 0.10.0 build 23. The Share Extension success screen
  shows "Closing in N…" and closes after 5 seconds; error screens never
  auto-close. Device acceptance pending.
- Website: "Add to the last upload" switch under the drop zone (localStorage
  `uploadiny.appendToLast`, default on) sends `append_to` for paste, drop and
  file picking; caption tracks the latest chunk through auto-refresh.
- No migration, dependency, or environment change.

## 2026-10-07 — v0.9.4 deployed via FAST

- Shipped `2064a717b9` on 2026-10-06; the first run stopped at the release
  fixture cleanup and `uploadiny-ship resume` completed it at 22:27:17 UTC.
  The completed receipt records website/API verification and signed iPhone
  0.9.4 build 22 installation and launch; no fixtures remained.

- Candidate iOS metadata: 0.9.4 build 22; no mobile behavior change.
- Paste upload: `clipboardFiles` reads `clipboardData.files`, falls back to
  file items (Safari screenshots via ⌘⌃⇧4), renames generic `image.*` to
  `pasted-<time>.<ext>`, and tolerates a non-element paste target.
- No migration, dependency, or environment change.

## 2026-10-07 — v0.9.3 deployed via FAST

- Shipped `1534a3e1ad` on 2026-10-06 at 22:14:16 UTC in 106 seconds. The
  completed receipt records website/API verification and signed iPhone 0.9.3
  build 21 installation and launch.

- Candidate iOS metadata: 0.9.3 build 21; no mobile behavior change.
- Editor header uses the same two columns as the editor body: Back, file name
  and pager over the drawing panel; Duplicate, Download original and Delete
  (moved from the sidebar, same confirmation) over the feedback sidebar.
- No migration, dependency, or environment change.

## 2026-10-07 — v0.9.2 deployed via FAST

- Shipped `c24a3895d8` on 2026-10-06 at 22:05:29 UTC in 108 seconds. The
  completed receipt records website/API verification and signed iPhone 0.9.2
  build 20 installation and launch.

- Candidate iOS metadata: 0.9.2 build 20; no mobile behavior change.
- Editor viewport: wheel and pinch zoom anchored at the pointer through the
  existing `changeZoom`/`sizeCanvas` path; Space + drag pans `#canvas-stage`
  without drawing, selecting or saving. Pointer mapping reads the canvas rect
  per event, so marks stay aligned and new marks land correctly when zoomed.
- No migration, dependency, or environment change.

## 2026-10-06 — v0.9.1 deployed via FAST

- Shipped `965d0ecc81` on 2026-10-06 at 21:54:15 UTC in 101 seconds. The
  completed receipt records website/API verification and signed iPhone 0.9.1
  build 19 installation and launch.

- Candidate iOS metadata: 0.9.1 build 19; no mobile behavior change.
- `ProjectController::show` resolves `?image=` against the project's complete
  chunks and the view renders the editor (gallery hidden, file name, position,
  video or drawing workspace) on first paint; unknown, foreign or draft images
  fall back to the gallery. A failed startup load returns to the gallery.
- Opening a file focuses `#editor-name`, not Back, so Space cannot leave the
  editor. The sidebar fits without scrolling: image context and move/copy
  actions are collapsible `<details>`, and the feedback box is 5 rows.
- No migration, dependency, or environment change.

## 2026-10-06 — v0.9.0 deployed via FAST

- Shipped `e630a0c297` on 2026-10-06 at 21:39:16 UTC in 94 seconds. The
  completed receipt records website/API verification and signed iPhone 0.9.0
  build 18 installation and launch.

- Candidate iOS metadata: 0.9.0 build 18; no mobile behavior change.
- Editor: Duplicate (`POST /images/{image}/duplicate`, ImageCopy clean mode via
  ImageDuplicator) makes a clean copy of the original in the same chunk and
  opens it; recordings excluded. Desktop editor fits the viewport with
  equal-height panels and a wider, internally scrolling feedback sidebar.
  The open image persists across reloads through `?image=<uuid>` (Back
  returns to the gallery).
- Project code chip copies on click; upload drop zone redesigned; empty
  workspace card centred.
- No migration, dependency, or environment change.

## 2026-10-06 — v0.8.0 deployed via FAST

- Shipped `212ed5bc92` on 2026-10-06 at 20:59:05 UTC in 105 seconds. The
  completed receipt records website/API verification and signed iPhone 0.8.0
  build 17 installation and launch.

- Candidate iOS metadata: 0.8.0 build 17. The Share Extension shows a
  byte-accurate progress bar ("Sending 1 of 3 · 64%") and preselects the last
  project uploaded to ("Last project used · Tap to change"), remembered in its
  own ThisDeviceOnly Keychain item after a confirmed upload. Device acceptance
  pending.
- Agent payloads (REST feedback/latest-chunk and every MCP tool, still
  identical): raw `annotations` points replaced by compact `marks`
  `{n, tool, color, position, area, note, note_area, from, to}` and
  `mark_count`; `feedback_updated_at` plus `feedback_settling` (edited in the
  last 60 seconds) and chunk-level `settling`. `list_projects` adds
  `latest_chunk`. Browser editor and phone responses keep full annotations.
  MCP server reports 1.2.0 with updated instructions. Live MCP use needs a
  descriptive User-Agent; Cloudflare rejects generic library agents with 403.
- The project page polls `projects.last-chunk` every 8 seconds while visible
  and swaps in new uploads; with the editor or a dialog open it only notifies
  and refreshes after returning to the project.
- Empty workspace card is centred in the free space.
- One additive migration: nullable `upload_images.feedback_updated_at`.

## 2026-10-06 — v0.7.0 deployed via FAST

- Shipped `61fab29b75` on 2026-10-06 at 20:19:05 UTC in 114 seconds. The
  completed receipt records website/API verification and signed iPhone 0.7.0
  build 16 installation and launch.

- Candidate iOS metadata: 0.7.0 build 16. The Share Extension shows "Add to the
  last upload" with the last upload's time and file count; the choice starts
  on and is remembered in its own ThisDeviceOnly Keychain item (never
  UserDefaults). Physical-device acceptance is pending.
- Server: `GET /api/projects/{project}/last-chunk` (phone `uploads:write`)
  returns the project's last completed upload. `chunks/start` accepts an
  optional `append_to` UUID; the draft stays separate until complete, then its
  files move into that upload, which gets a new completion time (it becomes
  "latest" for the agent). Unfinished or cancelled shares never touch it; a
  target deleted meanwhile, from another project, or unfinished yields a
  normal new upload.
- One additive migration: nullable `upload_chunks.append_to_chunk_id`
  (nullOnDelete). Previous code ignores it; rollback leaves it in place.

## 2026-10-06 — v0.6.0 deployed via FAST

- Shipped `3e71520d12` on 2026-10-06 at 19:50:36 UTC in 119 seconds. The
  completed receipt records website/API verification and signed iPhone 0.6.0
  build 15 installation and launch.

- Candidate iOS metadata: 0.6.0 build 15. No mobile behavior change.
- Editor ink: five quick colours, a hue/lightness gradient strip (click, drag
  or arrow keys), the native colour picker behind a rainbow ring, and a hex
  code field; all stay in sync and recolour a selected annotation.
- Drawing tools fit one row on laptop widths (icons with names on hover);
  wide screens keep labels.
- Empty workspace shows a centred "No projects yet" card with an animated
  illustration and a "Create your first project" button.
- No migration, dependency, or environment change.

## 2026-10-06 — v0.5.0 deployed via FAST

- Shipped `4f444267dc` on 2026-10-06 at 19:41:55 UTC in 108 seconds. The
  completed receipt records website/API verification and signed iPhone 0.5.0
  build 14 installation and launch.

- Candidate iOS metadata: 0.5.0 build 14. The app home screen and Share
  Extension use the light studio palette; physical-device review is pending.
- Backoffice redesigned per `DESIGN.md`: pale ground, floating white panels,
  one indigo accent, pill controls, upload chunks as a deck that fans open on
  hover. On phones the upload button floats in the thumb zone and the editor
  stacks its canvas above the feedback panel.
- Editor: a Select tool picks one mark; Delete/Backspace or the Delete button
  removes it through undoable history and autosave. Default annotation notes
  are about 40% smaller, and a note moves by dragging its frame or its tab.
- No migration, dependency, or environment change.

## 2026-10-06 — v0.4.0 deployed via FAST

- Shipped `1d15933ab0` on 2026-10-06 at 17:57:25 UTC in 105 seconds. The
  completed receipt records website/API verification and signed iPhone 0.4.0
  build 13 installation and launch.
- Candidate iOS metadata: 0.4.0 build 13. The Share Extension refuses files
  over 95 MB with a clear message before uploading; physical-device acceptance
  of that message is pending.
- The editor saves annotations and feedback automatically; ⌘Enter saves and
  ⌘←/→ move between files in a chunk.
- "Latest" feedback (agent REST/MCP, workspace badge, chunk destinations) now
  means the chunk that finished last, not the one started last.
- Each file is limited to 95 MB in the browser, server validation, and Share
  Extension because Cloudflare Free rejects requests over 100 MB. Production
  PHP-FPM (`/etc/php/8.5/fpm/php.ini`) and Nginx (`uploadiny.com`) were raised
  from 64 MB / 20 files to 100 MB / 100 files on 2026-10-06 via the corrected
  `bump-upload-limit.sh`; backups were kept on the server.
- MCP `get_asset` returns a tool error for screenshots over 250 MB instead of
  exhausting memory.
- Hardening: SQLite busy timeout (`DB_BUSY_TIMEOUT`, default 5000 ms); failed
  abandoned-draft cleanup is reported; moving the last image out of a chunk
  removes the empty chunk; a database failure during agent-key rotation
  restores the previous key file; account setup removes its credentials file
  when creation fails; the agent-token command explains a missing or ambiguous
  account; MCP/validation regex anchors reject trailing newlines; the chunk
  destination list is aggregated instead of loading every image.
- Optional environment keys with unchanged defaults: `DB_BUSY_TIMEOUT`,
  `UPLOADINY_FFMPEG_TIMEOUT`, `UPLOADINY_VISION_TIMEOUT`,
  `UPLOADINY_VISION_CONNECT_TIMEOUT`. No migration or dependency change.

## 2026-10-06 — v0.3.0 deployed via FAST

- Shipped `c781c660a1` on 2026-10-06 at 16:47:45 UTC in 101 seconds. The
  completed receipt records website verification and signed iPhone 0.3.0
  build 12 installation and launch.
- Candidate iOS metadata: 0.3.0 build 12. No mobile behavior change in this delta.
- Added the destructive MCP tool `delete_chunk(project_canonical, chunk_id)`.
  It uses the existing agent bearer key and deletes only assets in the selected
  project and exact reviewed completed chunk, with their private files and
  feedback. Assets moved elsewhere, newer uploads, and other chunks survive.
  A shared chunk record remains until no project has assets in it.
- File staging and database rollback reuse `WorkspaceDeletion`; deletion errors
  never report successful cleanup. Draft cancellation remains unchanged.
- The shared `do uploadiny` command now discovers project codes, inspects actual
  screenshots and recording frames, implements requested feedback, and pins the
  reviewed UUID for later explicit cleanup. Feedback is never deleted automatically.
- Backoffice key guidance now names the MCP deletion capability. Existing keys
  and stored token abilities remain compatible; agent REST access stays read-only.
- No dependency upgrade, migration, or new environment variable is required.
  Existing Laravel MCP 1.0.1 supports the added tool and destructive annotations.
- Local verification includes deletion/access/rollback regression coverage and
  HTTPS MCP smoke with disposable fixtures. Production proof belongs to shipping.

## 2026-10-06 — v0.2.0 deployed via LIGHT

- iOS 0.2.0 build 11 adds screenshot/recording previews, thumbnail and previous/
  next navigation, individual asset remarks, recording playback, and a refined
  Share review screen. Remarks remain attached through navigation and retry;
  publication requires each append response to confirm its saved remark.
- The private backoffice generates, reveals/copies, rotates, and revokes the
  read-only agent API key without disconnecting the iPhone. Every project has
  a permanent six-letter code; existing slug URLs stay compatible.
- Laravel MCP is a production dependency. `/mcp` exposes `list_projects`,
  `get_feedback`, `get_asset`, and `get_recording_frames` using the same bearer
  key. REST and MCP share feedback payloads; image content and timestamped
  JPEG recording frames retain exact asset remarks and revisions.
- Recording inspection requires FFmpeg on the web server. Configure its path
  with `UPLOADINY_FFMPEG_BINARY` when the web process cannot find `ffmpeg`.
- One additive migration assigns project codes while preserving existing
  projects, uploads, and feedback. Application rollback leaves this schema
  addition in place; the previous code can still create projects with a null
  canonical. Do not reverse migrations during shipping rollback.
- Local HTTPS MCP smoke passed for authentication, tool discovery, exact
  feedback, original/annotated images, real recording frames, and revocation.
  Provider connection and physical Share interaction remain separate checks.
- Preparation baseline: historical verified v0.1.5 at `9535c016`, deployed
  2026-10-04 with website/API health and signed iPhone 0.1.5 build 10 proof.
  Fresh physical Share Extension API proof was waived for that release.
  Completed local receipt: v0.2.0 at `a214ef031e06a96f74d0b648b675d1f6f1454a00`,
  2026-10-06 16:03:12 UTC, LIGHT, 854 seconds. The matching completed run records
  website/API verification and signed physical iPhone 0.2.0 build 11 installation
  and surviving process. Production HTTPS MCP tools, per-asset remarks, private
  images, recording frames, and the vision queue passed. Initial SQLite
  relocation completed; later FAST releases retain its stable physical path.
  Browser, physical Share Sheet interaction, and provider connection acceptance
  remain Bruno-owned. No backup or CI/CD ran.

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


## 2026-10-04 — 0.1.1 bearer-token and iPhone credential security

- `/api` uses Laravel Sanctum bearer authentication with session guards disabled.
  iPhone tokens can list projects and upload; coding-agent tokens can read
  projects, feedback, and private images. Each scope rejects the other role’s
  operations. The retired global upload key has no compatibility path.
- The iPhone obtains its short-lived limited token from an HTTPS sign-in endpoint.
  The Share Extension clears the password, persists only that token in a
  `WhenUnlockedThisDeviceOnly` Keychain item, rejects redirects, and removes its
  credential when a request returns 401. The release build no longer embeds a
  usable credential in its plist or build settings.
- Website login and device-token attempts are rate limited with normalized,
  bounded credential keys. Existing website sessions and Bruno’s current
  password remain unchanged. The password strength is an accepted residual
  risk until Bruno elects to change it.
- The workspace has a `Revoke iPhone access` action, and the private
  `uploadiny:agent-token` command issues, rotates, or revokes the separate
  coding-agent token without printing it.
- Regression coverage exercises direct issued bearer tokens, legacy/wrong/
  revoked/expired rejection, ability separation, token replacement isolation,
  malformed throttled credential input, private download headers, and the
  native Keychain/HTTPS/no-redirect/no-embedded-token source contract.


## 2026-10-04 — 0.1.2 annotation canvas release

- The editor groups pen, arrow, line, rectangle, ellipse and whole-mark eraser
  tools with adjustable thickness, preset/custom colors, actual-scale zoom and
  fit-to-canvas. The image stays centered on a scrollable canvas; drawings retain
  normalized coordinates and the original image remains untouched.
- Undo/redo now restores complete drawing states, including erasing and clearing.
  Existing Cmd/Ctrl shortcuts remain active outside editable fields.
- Persistent drawing-state coverage proves erase/undo/redo, clear/undo,
  cancelled-eraser restoration, history reset when switching images, and stable
  normalized coordinates/thickness across zoom and resize. Shape geometry and
  server persistence remain covered separately.


## 2026-10-04 — callouts and recording support

- Annotation creates a target rectangle, connected arrow and editable text box.
  Both boxes move and resize independently, share the selected ink color, and
  participate in undo/redo. Callout text and geometry persist for the agent;
  the annotated PNG includes the text.
- Website and native sharing accept MP4/MOV/M4V recordings alongside images
  in one upload chunk. Videos stay private, play with native browser controls,
  support range requests for seeking, and accept written timestamped feedback.
  Videos skip the still-image description workflow. Existing API names remain
  compatible; each entry adds media_type, and videos use not_applicable for
  description_status. Unsupported browser codecs offer original download.
- Immediate checks: local mocked-API canvas creation/text/width/height/color/
  undo/redo/export and synthetic MP4 play/pause/seek/comment save; PHP mixed
  chunks/private range responses/agent feedback; Vite, JS tests, PHPStan,
  mobile TypeScript/security contracts and native Swift typecheck.
- Release coverage still needs persistent callout geometry/history cases:
  edge placement, independent target/text-box resize and movement, clamping,
  pointer cancellation, text/color undo, reopen/edit/export, and escaped text.
  Physical iPhone mixed screenshot/recording sharing and Safari playback of
  an actual iPhone recording remain unverified.


## 2026-10-04 — 0.1.3 build 8 deployment

- Deployed the current workspace to uploadiny.com and installed the signed
  0.1.3 build 8 on Bruno's paired physical iPhone. App-list and surviving
  process evidence confirm the installed version and launch.
- Production smoke verified a mixed screenshot/MP4 chunk, private video range
  requests, phone/agent ability separation, authenticated editor/player markup,
  callout text persistence, annotated PNG download and recording feedback.
  Only the release-created project, tokens and session were removed afterward.
- Website source and asset hashes match the deployment manifest. Production
  queue worker reloaded the new code. Existing data, account, credentials and
  private originals remain in place. No migrations were added.
- Targeted PHP checks: 28 tests / 419 assertions. JS: 12 tests. PHPStan, Pint,
  PHP Insights and Composer advisory checks pass. Native Swift typecheck,
  TypeScript, native contract checks and signed device build/install pass.
- Deployment uses uncommitted workspace source based on v0.1.2; no Git commit,
  tag or push was requested. .deployment.json records exact per-file hashes.
  Physical Share Sheet interaction and Safari playback of an actual iPhone
  recording remain Bruno-owned acceptance checks.


## 2026-10-04 — website playback buttons

- Recording cards expose Play recording; the video panel adds a prominent
  Play/Pause/Replay button while retaining native timeline, volume and
  fullscreen controls. A browser that blocks playback after opening the
  card can start it through the dedicated player button.
- Local synthetic-video verification covers visible Play/Pause labels and
  playback state, seeking, written feedback and switching back to an image.
  Desktop/mobile player layouts were inspected.


## 2026-10-04 — chunk gallery, navigation and transfers

- One gallery card represents each upload chunk. Its latest file is the cover;
  stacked layers and a file count indicate additional files. Chunk cards sit
  together in a responsive grid.
- The editor provides first/previous/next/last navigation within the chunk.
  Switching images or recordings, returning to the project, and transferring
  files saves pending annotations and comments first. A failed save keeps the
  current file open with its feedback intact.
- Copy or move an individual file into another existing chunk, including a
  chunk in another project. Moves retain originals and feedback; copies own
  independent original/annotated files and retain comments, annotations and
  descriptions. Failed copy transactions remove only newly copied files.
  Empty source chunks are hidden from the gallery and destination chooser.
- Validation: PHP suite 36 tests / 493 assertions; JS suite 12 tests; PHPStan,
  Pint, PHP Insights thresholds and Composer advisory checks. Built frontend
  fixture checks cover navigation, image/video feedback retention and failed
  saves. Desktop/mobile gallery and editor layouts inspected using synthetic
  screenshots. Real Safari interaction remains Bruno-owned acceptance.
- Website-only update; the installed iPhone app remains 0.1.3 build 8.
- Production smoke passed: mixed screenshot/recording chunks, private video
  seeking, cross-project copy/move, independent copies surviving deletion of
  their source, preserved callout/comments, stacked cards and hidden empty
  source chunks. Only temporary smoke projects, tokens and session were
  removed. Deployed file hashes match the deployment manifest.


## 2026-10-04 — stale login recovery

- Reproduced a stale-token login POST returning Laravel's bare 419 page.
  Fresh production login forms reach credential validation; cookies are
  secure and login responses are private/no-cache. The screenshot alone
  does not establish why Bruno's token became stale.
- Browser login POSTs with a rejected security token now redirect with 303
  to a fresh sign-in form and a clear expiry message. Credentials are not
  retried or flashed. CSRF validation remains active; JSON login requests
  and other forms keep their existing 419 response.
- New regression tests enable real CSRF middleware behavior, cover stale
  form recovery, valid-token authentication and unchanged rejection for
  JSON/other forms. Login/security checks: 11 tests / 95 assertions;
  Pint, PHPStan and PHP Insights thresholds pass.


## 2026-10-04 — 0.1.4 build 9 public-source release

- The versioned public source includes private screenshot and recording upload
  chunks, callouts, chunk navigation and transfers, stale-login recovery, and
  the refined Latest uploads gallery. The newest completed upload is clearly
  marked while each card is headed by its date.
- iOS version 0.1.4 build 9 keeps the production HTTPS endpoint and limited
  Keychain-held device credential contract. The source tag and signed device
  build use the same version and build metadata.


## 2026-10-04 — 0.1.5 build 10 recording first-frame previews

- Recording cards and stacked gallery layers lazily render a cached JPEG of
  the recording's first frame from the existing private preview route. The
  feedback player uses the same frame while loading and clears late results
  after switching files or leaving the editor.
- Codec, network, and frame-extraction failures retain the recording fallback;
  playback does not start during extraction. The native app is rebuilt as
  version 0.1.5 build 10 for this product release.
