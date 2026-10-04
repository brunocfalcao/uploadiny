# Uploadiny

Uploadiny is a private workspace for collecting product-feedback images. It
groups one or more uploads into a project chunk, lets the owner annotate and
comment on each image, and exposes the latest completed chunk to an
authenticated coding agent.

The Laravel website and API live at the repository root. The Expo iPhone app
and Share Extension live in [`mobile/`](mobile/); the extension sends uploads
to `https://uploadiny.com/api`.

## Product behavior

- Browser and Share Extension uploads create a draft chunk, append each image,
  and publish only after every expected image arrives.
- Originals and annotated PNGs are private. Browser image routes require an
  authenticated session; `/api` accepts only Laravel Sanctum bearer tokens,
  never a browser session or a global static key.
- The Share Extension signs in once over HTTPS and stores only its limited
  `projects:read`/`uploads:write` token in the device-only Keychain. It never
  stores a password or embeds a usable credential in the app.
- The coding-agent token is separate and read-only for project feedback. The
  workspace can revoke iPhone access, and the private `uploadiny:agent-token`
  command rotates or revokes agent access without exposing the token in output.
- Projects retain current images when images are moved. Deleting a project
  removes only the images still assigned to it.
- Feedback stores normalized drawings, comments, annotated images, and a
  revision number so stale editor writes are rejected.
- Vision descriptions run asynchronously on the database queue. Pending or
  failed descriptions never block feedback or retrieval.

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
npx expo-doctor
```

## Release targets

The public source repository is
[`brunocfalcao/uploadiny`](https://github.com/brunocfalcao/uploadiny). The
production website is `https://uploadiny.com`; its database, private storage,
queue state, environment, and application identity are persistent production
state and are never copied from a local checkout.

The iOS marketing version is `0.1.2`; this annotation-canvas release is build `7`.
Each signed device installation must increment `mobile/app.json` `ios.buildNumber`.
