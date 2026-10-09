# uploadiny.test — Project Guidance

Shared project instructions for Codex and Claude.

## Purpose and boundaries

Private product-feedback workspace with projects, upload chunks, image annotation, comments, and authenticated agent retrieval. Browser uploads, device uploads, image access, and agent access have distinct authentication and token abilities.

## Documentation

Start at the repository `README.md`. When the sibling documentation checkout is available, continue at `../docs/upload/README.md`, then read the relevant architecture, product, and feature documents. Verify their claims against this checkout.

## Working rules

- Address Bruno directly and use concise caveman full communication; code, commits, and PR descriptions remain professional language.
- Read relevant source, callers, configuration, tests, and scoped instructions before editing. Current code wins over unsupported documentation; planned and historical behavior must be labeled.
- Follow the closest working implementation. Keep changes within Bruno's requested product behavior; preserve unrelated dirty work and user data.
- Bruno owns product decisions. The agent owns technical choices. Ask about material product ambiguity, not class names or file placement.
- Never commit, push, deploy, send messages, or perform destructive operations unless the task authorizes them. Never expose credentials.
- Run the smallest relevant existing executable checks. Ordinary implementation defers new coverage to the release pass; authentication, privacy, billing, data integrity, concurrency, and production regressions require immediate coverage. Do not claim browser/device/provider acceptance without observing it.
- Resolve shared `do` workflows from `~/Herd/.dynamic-commands/` freshly. Read applicable global rules and project/nested instructions. Keep handoffs concise and outcome-first.

## Dependency contract

Manifest requirements: PHP `^8.2`, `laravel/framework` `^12.0`. Exact resolved versions come from `composer.lock`; runtime versions require a fresh check.

## PHP and Laravel conventions

- Verify installed package versions from Composer/Boost before choosing APIs. Use current documentation for those versions and activate relevant installed skills.
- Prefer project-aware Boost tools when available. Read `.ai/rules/index.md` and applicable rule files before entering an affected area. Record durable rules only when Bruno asks.
- Follow sibling file structure, naming, types, casts, and observer patterns. Use explicit PHP types and curly braces; useful PHPDoc replaces redundant inline commentary. Do not create abstractions, dependencies, or base directories without a task reason.
- Reuse existing Blade/Livewire components and frontend conventions. Build assets after frontend changes. Livewire actions preserve the same validation and authorization boundaries as HTTP requests.
- Use the existing test runner and factory states; never convert or remove tests merely because a generic guideline names a different runner. Read the applicable testing skill before authoring coverage.
- Format changed PHP with the project's Pint workflow. Herd serves local Laravel sites; do not launch a second web server to verify a Herd-served app.
- Schema changes retain existing column attributes and relationships. Do not introduce cascading deletes. Preserve existing queue, notification, and shared-package ownership.

## Project constraints

- Preserve private storage, Sanctum-only API authentication, phone/agent ability separation, complete/draft chunk transitions, feedback revisions, and moved-image deletion behavior. API token and Share Extension changes require immediate security coverage. Keep the mobile Keychain and native Share Extension contracts aligned with the server.
- Agent API access is managed at `/agent-access`. Backoffice keys read feedback, authorize explicit completed-chunk deletion through MCP, and remain nonexpiring until rotated or revoked; the CLI preserves its explicit lifetime option. Both use `AgentAccess` and the same protected credential file. Project canonicals are generated six-letter codes, stay stable across name/slug changes, and select feedback at `/api/feedback/{project:canonical}`. Keep slug-based browser/mobile routes compatible.
- The remote MCP endpoint `/mcp` uses the same dedicated agent Sanctum abilities and key as the agent API. `routes/ai.php` registers five tools: project discovery, latest completed feedback, private screenshot content, timestamped recording frames, and explicit completed-chunk deletion. `delete_chunk` requires the pinned chunk UUID, project canonical, and review_token from the reviewed feedback; changed contents require renewed review and consent. Deletion preserves assets assigned elsewhere and uses `WorkspaceDeletion` staged-file rollback. Committed deletions retain staged-file cleanup intents until removal succeeds; the scheduler retries these every minute. The shared `do uploadiny` command retains reviewed chunk identity and review_token and never deletes automatically. REST remains read-only for the agent; MCP deletion uses the existing agent key without changing its stored abilities. `FeedbackReader` keeps REST and MCP payloads aligned. `laravel/mcp` must remain a production dependency. Recording frames require FFmpeg, configured through `services.uploadiny.ffmpeg_binary` / `UPLOADINY_FFMPEG_BINARY`; the web process must be able to execute it. `uploadiny:mcp-smoke` verifies the configured application with disposable fixtures and credentials; its URL must target the same database. Keep HTTPS verification enabled, supplying the trusted local CA when needed.

## Code map

Agent retrieval options: `get_feedback` includes saved annotated screenshots
with marks by default (`include_annotated_images: false` disables inline media).
`image_width` on `get_feedback` and `get_asset` returns temporary resized PNGs
without upscaling or changing private originals; retain decoded-image and
combined-response memory checks. All three feedback/media read tools accept
`include_descriptions: false` to omit AI metadata without altering stored
descriptions or review-token contents. `after_chunk` on MCP and agent REST
feedback returns only the latest completed batch newer than that project-scoped
UUID, ordered by completion/legacy creation time then ID. Unavailable cursors
fail clearly; no newer batch returns `chunk: null`. Cursors do not detect edits
or append operations in the same batch. REST also accepts
`include_descriptions=0`; inline media remains MCP-only.

`routes/web.php`, `routes/api.php`, `routes/console.php`, `bootstrap/app.php`, `bootstrap/providers.php`, `app/Services/`, `app/Http/Controllers/`, `mobile/`, `tests/`. Inspect these entrypoints and their actual callers for the area being changed.

## Verification

Available entrypoints: `composer test`, `composer quality`, `npm run build`, `npm run test`. Choose the narrowest relevant check; a listed full-suite, build, install, or packaging command is not an instruction to run it for every edit. Inspect test environment and side effects first.

Orientation examples read: `app/Http/Controllers/AgentController.php`, `tests/Feature/ApiAccessTokenTest.php`. Re-read the closest example for the actual task rather than copying an unrelated one.

## Keep this file current

Please update `PROJECT.md` whenever project guidelines, architecture, workflows,
or verified constraints change. Correct documentation that is not supported by
current codebase evidence; codebase wins. Distinguish implemented behavior from
plans and historical records. Keep `AGENTS.md` and `CLAUDE.md` as thin pointers
to this shared file so Codex and Claude follow the same project guidance.
