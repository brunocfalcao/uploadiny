# uploadiny.test mobile — Project Guidance

Read `../PROJECT.md` first. This file adds the mobile-specific rules shared by Codex and Claude.

## Client boundary

The Expo/React Native client lives here. Preserve device authentication, API payloads, local recovery, and native lifecycle behavior; verify the matching Laravel API before changing a client contract.

The native Share Extension reviews prepared assets before upload, with previews, recording playback, and independent optional feedback per asset. Send each remark as `comments` on its draft-image append; confirm the returned asset feedback before completing the chunk. Notes stay in memory through navigation, project changes, and retry or sign-in recovery within the open extension. Closing ends that local draft. An uncertain completion response must not offer an automatic re-upload. Deploy the matching backend before installing a phone build that requires saved-feedback acknowledgements. Before phone delivery, verify the deployed incremental start/append/complete API with separate notes and blank feedback; a one-shot upload smoke check does not cover this contract.

## Version and structure

Expo manifest requirement: `~57.0.9`. Verify the lockfile and installed version before using APIs. Read the matching versioned documentation at https://docs.expo.dev/versions/v57.0.0/ for Expo changes. Inspect `package.json`, the app configuration, native code/plugins, and sibling screens before editing. Keep provider secrets out of the client.

Code map: `App.tsx`, `src/`, `plugins/`, `native/`, `scripts/`, `app.json`, `ios/`. Generated native files must be handled through the existing prebuild/plugin workflow when applicable.

## Verification

Node must satisfy the manifest's checked engine range. `expo-doctor` 1.20.4 and `pod-install` 1.1.0 are locked development dependencies; use the declared doctor script and `npx --no-install` in deployment so missing tools fail explicitly.

Append mode requires successful last-upload lookup for the current project/generation; failure requires retry. The upload coordinator owns session invalidation and serial background multipart staging. Keep prepared originals and notes for sign-in/retry, removing multipart copies after each response and all owned files on success/close. Controllable Foundation URLSession tests exercise success, 401, cancellation, and lost completion; UIKit/browser/device acceptance remains separate.

Declared checks: `npm run typecheck`, `npm run doctor`, `npm run test:share-extension`. Run the relevant existing unit/type checks. Native build, simulator execution, and physical-device acceptance are separate evidence; do not install or launch on a device unless the task requires it.

## Keep this file current

Please update `PROJECT.md` whenever project guidelines, architecture, workflows,
or verified constraints change. Correct documentation that is not supported by
current codebase evidence; codebase wins. Distinguish implemented behavior from
plans and historical records. Keep `AGENTS.md` and `CLAUDE.md` as thin pointers
to this shared file so Codex and Claude follow the same project guidance.
