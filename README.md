# Uploadiny

Uploadiny is a private workspace for collecting product-feedback images. It
groups one or more uploads into a project chunk, lets the owner annotate and
comment on each image, and exposes the latest completed chunk to an
authenticated coding agent.

The Laravel website and API live at the repository root. The Expo iPhone app
and Share Extension live in [`mobile/`](mobile/); the extension sends uploads
to `https://uploadiny.com/api`.

## Product behavior

- The Share Extension previews screenshots and recordings before upload, with
  independent feedback per asset, thumbnail navigation, and a review screen.
  It verifies each saved remark before publishing the group.
- Browser and Share Extension uploads create a draft chunk, append each image,
  and publish only after every expected image arrives.
- Originals and annotated PNGs are private. Browser image routes require an
  authenticated session; `/api` accepts only Laravel Sanctum bearer tokens,
  never a browser session or a global static key.
- The Share Extension signs in once over HTTPS and stores only its limited
  `projects:read`/`uploads:write` token in the device-only Keychain. It never
  stores a password or embeds a usable credential in the app.
- The coding-agent token is separate. It reads project feedback and also
  authorizes explicit completed-chunk deletion through MCP. The
  backoffice’s **Agent API access** page generates, copies, rotates, or revokes
  it without disconnecting the iPhone. Keys generated there do not expire;
  rotation immediately invalidates previous agent keys. The private
  `uploadiny:agent-token` command also rotates or revokes the same credential
  without exposing it in output (its default lifetime remains 90 days).
- Every project has a unique, permanent six-letter `canonical`, displayed as
  its **Project code**. Existing projects receive codes during migration.
  Agents retrieve the latest completed batch using
  `GET /api/feedback/{canonical}` with the agent bearer key. Agent REST
  access remains read-only. Existing
  project URLs and slug-based API endpoints remain available.
- Projects retain current images when images are moved. Deleting a project
  removes only the images still assigned to it.
- Feedback stores normalized drawings, comments, annotated images, and a
  revision number so stale editor writes are rejected. Keyboard users can add
  and select annotations without a pointer.
- Vision descriptions run asynchronously on the database queue. Pending or
  failed descriptions never block feedback or retrieval. Queue failure keeps
  the published upload and leaves a retryable description.

## Local development

Requirements: PHP 8.5, Composer, Node.js, and SQLite. Copy `.env.example` to
`.env`, configure only local credentials, then install the locked dependencies:

```sh
composer install
npm ci
touch database/database.sqlite
php artisan key:generate
php artisan migrate
npm run build
```

Create the private account with `php artisan uploadiny:account`, then run a
database queue worker with `php artisan queue:work` while testing vision jobs.
Run the Laravel scheduler (locally, `php artisan schedule:work`) to retry
committed staged-file cleanup each minute. Deployment must apply the new
failed-job and staged-cleanup migrations and keep the scheduler active.

## MCP access

The Laravel MCP server is served at `/mcp` over HTTP. Locally, use
`https://uploadiny.test/mcp`; after deployment, use `https://uploadiny.com/mcp`.
Configure the client with the backoffice's Agent API access key as an
`Authorization: Bearer` header. Rotating or revoking that key immediately
changes both REST and MCP access. Phone keys and browser sessions cannot use
the MCP server.

The server exposes five tools:

- `list_projects`: discover project names and their six-letter codes.
- `get_feedback(project_canonical)`: retrieve the latest completed batch,
  including exact comments, annotations, asset IDs, feedback revisions, and
  the `review_token` identifying those reviewed contents. Marked screenshots
  include their saved annotated image in this same call by default, labeled
  with the asset UUID. Set `include_annotated_images: false` for metadata only.
  Missing or unsafe inline images return an explanation alongside the feedback;
  private media URLs remain available.
- `get_asset(asset_id, variant)`: inspect the original or annotated screenshot
  as image content. Original recordings return metadata and a private download
  URL instead; that URL requires the same bearer key. Images that cannot
  safely fit the PHP inline-response memory budget return a safe error with
  this authenticated original-download link; originals remain intact.
- `get_recording_frames(asset_id, timestamps)`: inspect up to five JPEG frames
  at specified seconds, with the original feedback alongside them. Omitting
  timestamps returns the first frame. Frames stay within 1280 pixels per side
  without upscaling and are not saved as new assets.
- `delete_chunk(project_canonical, chunk_id, review_token)`: permanently delete the exact
  completed feedback chunk's assets currently in that project, including
  comments, annotations, and private files. Assets moved elsewhere survive;
  the shared chunk record survives while other projects still have assets.
  The tool uses the same agent key and is marked destructive. Call it only
  after the owner explicitly requests deletion, retaining the reviewed UUID
  and `review_token` rather than selecting a newer upload. If files, notes,
  annotations, or descriptions changed after review, review the updated
  chunk and obtain fresh explicit cleanup consent.

For cheaper screenshot inspection, pass `image_width: 900` to `get_feedback`
or `get_asset`. Resized PNGs preserve aspect ratio and transparency, never
upscale, and do not change stored files. Omitting width preserves exact stored
bytes. Width accepts integers from 1 to 4096; decoding and combined batch
responses retain the safe inline memory budget.

Pass `include_descriptions: false` to `get_feedback`, `get_asset`, or
`get_recording_frames` to omit the AI description, status, error, and model.
Owner comments and marks remain intact. Descriptions are still generated and
stored; this option only controls retrieval. Review tokens still identify the
full stored feedback, including descriptions.

Remember the returned `chunk.id` and pass it as `after_chunk` on a later
`get_feedback` call. The tool returns the latest completed batch strictly newer
than that cursor, using completion time (creation time for legacy rows), then
database ID to break ties. `chunk: null` means no newer batch. This is a
client-held batch cursor, not a shared seen marker or a history feed. It does
not detect edits or appended assets in the same batch. Deleted, draft, and
other-project cursors return an error; omit the cursor to establish a new one.
Both agent REST feedback routes also accept `after_chunk` and
`include_descriptions=0`, with the same metadata and cursor behavior. Inline
images and resizing are MCP options.

`do uploadiny <request>` uses the shared command at
`~/Herd/.dynamic-commands/uploadiny.md` to select a project, inspect its latest
feedback, and implement requested changes. An explicit follow-up such as
`do uploadiny delete the chunk, all good` deletes the pinned reviewed chunk.
The command prefers connected MCP tools and supports authenticated HTTPS MCP
calls when the client has not registered the server. Client credentials must
be configured separately; the command never issues or rotates keys.

Recording inspection requires FFmpeg on the application server. Set
`UPLOADINY_FFMPEG_BINARY` to its executable path when the web process cannot
find `ffmpeg` on its PATH. Client configuration and provider acceptance must
be checked separately from server verification; no OAuth flow is provided.

Run `php artisan uploadiny:mcp-smoke` against the configured `APP_URL` to check
real HTTP authentication, initialization, all tools, image/frame delivery,
and revocation. It requires an existing private account and working FFmpeg,
uses temporary projects, assets, and five-minute keys, and cleans its fixtures
on exit without rotating the managed key. The URL must point to this same
application and database. For Herd HTTPS trust, pass
`--ca="/Users/falcaob/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem"`;
certificate verification remains enabled.

## Upload and history recovery

The browser uses 30-second control requests and ten-minute file/publication
requests, matching the iPhone limits. Cancel stops the active transfer and
cancels its unpublished draft. A lost final response asks the owner to check
the project before uploading again; completed groups cannot be cancelled.
The iPhone requires a successful current-project last-upload lookup in append
mode. A failed lookup offers retry and retains prepared media and notes.
Multipart staging runs on a serial background worker; each sent multipart
copy is removed while review originals remain available for sign-in/retry.

The gallery pages 24 completed chunks and fetches full feedback when a file
opens. Direct links to older files retain navigation within their chunk.
Transfer choices are reused within the page and invalidated after changes.
Recording posters retain at most 8 MiB of decoded data-URL string storage in
an LRU cache; expired posters can be regenerated from the private original.

## Verification

```sh
php artisan test --compact
npm test
npm run build
composer run quality
```

`composer run quality` runs PHPStan, PHP Insights code metrics, and Composer's
locked dependency advisory check. PHP Insights 2.12 is pinned because its
newer release conflicts with this project's PHPUnit dependency. PHPMD is not
part of the enforced gate: its current release emits PHP 8.5 deprecations into
its own report stream.

For iPhone source checks, run these from `mobile/`:

```sh
npm ci
npm run typecheck
npm run test:share-extension
npm run doctor
```

## Release targets

The public source repository is
[`brunocfalcao/uploadiny`](https://github.com/brunocfalcao/uploadiny). The
production website is `https://uploadiny.com`; its database, private storage,
queue state, environment, and application identity are persistent production
state and are never copied from a local checkout.

The release candidate is `v0.14.0`, candidate, not deployed. Its iOS
marketing version is `0.14.0`; this release build is `32`. The website adds
**Delete all Chunks** beside Project settings. After confirmation, it clears
all published and draft feedback in the current project, including private
files, comments and annotations. The project and files assigned to other
projects remain. Deletion uses the existing transactional recovery journal
and durable cleanup retries.

No migrations, new environment keys or dependency versions are required.
Runtime and shipped mobile dependencies are unchanged.

The previous release, `v0.13.1` (`c3beef1cd1`), completed FAST shipping on
9 October 2026 at 22:32:16 UTC in 103 seconds. Its matching completed receipt
records website/API verification and signed physical iPhone 0.13.1 build 31
installation and launch. Manual browser, Share Sheet, Keychain persistence,
and VoiceOver acceptance remain separate.
Each signed device installation must increment `mobile/app.json` `ios.buildNumber`.
