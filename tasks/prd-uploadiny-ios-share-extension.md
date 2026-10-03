# PRD: Uploadiny iOS Share Extension

## Overview

Uploadiny removes the manual step of transferring iPhone screenshots and files to the terminal workflow. Bruno shares any file from iOS, selects Uploadiny, and the file is uploaded immediately into the same server-backed upload collection used by `upload.waygou.com`. The existing `do screenshot` workflow can then retrieve the uploaded image from that shared location.

## Goals

- Make a shared iPhone file available on the Uploadiny server without opening the website.
- Preserve one upload collection and storage behavior across the website and iPhone.
- Complete a successful Share Sheet upload with no confirmation step or extra user input.
- Provide a clear terminal state for both success and failure.
- Limit the MVP to Bruno's personal iPhone.

## User Stories

### US-001: Share any file to Uploadiny

**Description:** As Bruno, I want Uploadiny in the iPhone Share Sheet so that I can send any file directly to my upload server.

**Acceptance Criteria:**

- [ ] Uploadiny appears as a Share Sheet destination for files.
- [ ] Selecting Uploadiny starts the upload immediately.
- [ ] The flow does not ask for confirmation, a filename, or other metadata.
- [ ] Screenshots and non-image files are accepted within the server's existing upload rules.

### US-002: Store the file in the existing upload collection

**Description:** As Bruno, I want iPhone uploads stored exactly like browser uploads so that existing retrieval workflows continue to work.

**Acceptance Criteria:**

- [ ] The server uses the existing upload storage flow and filename convention.
- [ ] An iPhone upload appears in the same collection shown by `upload.waygou.com`.
- [ ] The returned download URL uses the existing `/d/{uuid}` behavior.
- [ ] Existing browser uploads remain unchanged.
- [ ] An uploaded screenshot becomes retrievable by `do screenshot` from the shared server location.

### US-003: Finish with clear success or failure

**Description:** As Bruno, I want the Share Sheet flow to finish clearly so that I know whether the file reached the server.

**Acceptance Criteria:**

- [ ] A successful upload shows a brief success state and closes automatically.
- [ ] A failed upload remains open and displays the server or connectivity error.
- [ ] The failure state provides a Close button.
- [ ] The MVP does not provide a Retry action.

### US-004: Restrict personal mobile access

**Description:** As Bruno, I want the mobile upload path intended only for my iPhone so that it is not an unauthenticated public upload API.

**Acceptance Criteria:**

- [ ] Mobile upload requests require the configured personal-device credential.
- [ ] Missing or incorrect credentials are rejected without storing a file.
- [ ] Credential values are not committed to the repository or exposed in logs and responses.

## Functional Requirements

- FR-1: Uploadiny must be available from the iOS Share Sheet for any shared file.
- FR-2: Selecting Uploadiny must begin uploading the first shared file immediately.
- FR-3: The mobile upload path must apply the same file-size and blocked-extension rules as the website.
- FR-4: The server must store mobile uploads through the same filesystem-backed upload flow used by the browser.
- FR-5: The server must return the existing file name, size, and `/d/{uuid}` download URL response.
- FR-6: The mobile upload path must reject requests that do not carry Bruno's configured personal-device credential.
- FR-7: Success must show briefly and then close the Share Sheet extension automatically.
- FR-8: Failure must show a readable error and a Close button without retrying automatically.
- FR-9: Existing browser upload, download, listing, individual deletion, and delete-all behavior must remain unchanged.

## Non-Goals

- Supporting other users or devices.
- Upload history or file browsing inside the iPhone app.
- Retrying a failed upload from the error state.
- Editing, renaming, compressing, or annotating files before upload.
- Uploading multiple shared files in one Share Sheet action.
- Changing `do screenshot` behavior.
- Changing the website's existing upload experience or storage convention.

## Design Considerations

- The Share Sheet surface is intentionally minimal: uploading, success, and error states only.
- The Uploadiny name and icon should make the destination recognizable in the Share Sheet.
- Error text must be readable and must not expose credentials or internal server details.

## Technical Considerations

- The current Laravel controller stores files on the public disk as `<uuid>__<sanitized-original-name>` and returns `/d/{uuid}` URLs; the mobile path must reuse that implementation.
- The existing browser route uses web middleware and CSRF protection. The iPhone requires a separate stateless route protected by a personal-device credential.
- The iPhone client follows the existing Expo/React Native development baseline while using a native iOS Share Extension for Share Sheet integration.
- Large files must be uploaded from a file URL rather than requiring the extension to hold the complete payload in memory.
- The build must keep the server credential outside version control and provide it to the signed personal build through local build configuration.

## Success Metrics

- 100% of supported test uploads arrive in the same server directory and listing as browser uploads.
- A screenshot shared from Bruno's iPhone is retrievable through the existing download path and `do screenshot` workflow.
- Unauthorized mobile upload requests store zero files.
- Success closes automatically; failure remains visible with a Close button.
- Targeted backend tests, mobile type checking, Expo validation, signed iOS build, install, launch, and physical-device process checks pass.

## Open Questions

None.
