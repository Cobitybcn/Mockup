# Pinterest integration preparation

OAuth, encrypted renewable tokens, board selection, explicit confirmation, and Pin creation are implemented. Apply migrations `000001` and `000002` in order. Existing administrator connections become `platform`; existing non-administrator connections become `artist`. Administrators may then connect a separate artist account.

By default, users authorize through the official Artwork Mockups app. An artist who owns a separate Pinterest developer app can configure that App ID and secret inside their private Connections panel. The secret is encrypted at rest and never displayed again. OAuth tokens are stored separately by user and purpose, so the administrator platform identity and every artist identity remain isolated.

Required environment variables are `PINTEREST_APP_ID`, `PINTEREST_APP_SECRET`, and `PINTEREST_REDIRECT_URI`. The app secret is backend-only and must not be committed, rendered, logged, or sent in an error. In Google Cloud production, load `PINTEREST_APP_SECRET` from Secret Manager rather than a deployed environment file.

OAuth state is random, session-bound, single-use, and expires after ten minutes. Tokens use authenticated encryption and refresh automatically. Every Pin requires a fresh explicit confirmation. `artist` connections are used for artwork content; `platform` is reserved for administrator-led promotion of Artwork Mockups.

## Artwork Mockups production identity

The platform Pinterest identity is **Artworks Mockups (`@artworkmockups`)**. The deployed platform app ID is `1589233`. App `1589266` belongs exclusively to Maurizio Valch and must be stored as his account-specific developer app; it must never replace the platform environment configuration.

The Social Media Board lets administrators choose between the platform and artist Pinterest identities. When the platform connection is available, it is the administrator default; the selected purpose is preserved in the draft, scheduled job, and worker publication. Non-administrators can only use their own `artist` connection.

App `1589233` was verified while signed into `@artworkmockups`. Its current level is **Trial access active**. The exact callback `https://artworkmockups.com/integrations/pinterest/callback` is registered, the backend secret belongs to the same app, and the `platform` identity was reconnected through Sandbox OAuth on 2026-07-27. During the review demo, the callback visibly pauses before the server exchanges the code at `https://api-sandbox.pinterest.com/v5/oauth/token`.

Both Cloud Run services currently use `PINTEREST_API_ENVIRONMENT=sandbox`, `PINTEREST_DRAFT_PUBLIC_MEDIA_ENABLED=true`, and `PINTEREST_LIVE_PUBLISH_ENABLED=true` while Standard access is under review. The OAuth token is stored encrypted and used for Sandbox API calls; the legacy `PINTEREST_SANDBOX_TOKEN` remains only as a fallback when no connected platform identity exists. This enables the explicitly confirmed publishing path and does not publish automatically. Sandbox Pin `1121888957218553543` was created through the API and verified while signed into `@artworkmockups`.

For the first controlled Pin:

1. Select one reviewed mockup and the controlled `Artwork Mockups Sandbox Test` board.
2. Review the exact title, description, destination URL, image crop, and alt text.
3. Obtain explicit confirmation immediately before publication.
4. Publish one Pin and verify its external ID/link while signed into `@artworkmockups`.
5. Do not enable normal batches or scheduled Pinterest publication until that result is verified.
6. After Pinterest grants the public access tier, repeat one controlled public Pin before normal publishing.

## Safe production migration

1. Run `php scripts/audit_pinterest_connection_purposes.php` and save the count-only output.
2. Back up the database using the normal infrastructure backup process. Never export tokens into logs or task output.
3. Run `php migrations/20260711_000002_add_pinterest_connection_purpose.php up`.
4. Run the audit again. The existing administrator connection must appear as `platform`; non-administrator connections must appear as `artist`; `conflicts` must be zero.
5. Open the Pinterest connections page as an administrator and verify the platform account remains connected before starting a new artist OAuth flow.
6. Rollback is allowed only when no user has both purposes. The migration refuses a destructive rollback when both connections exist.

Pinterest currently documents all four scopes for its create-Pin workflow: `boards:read`, `boards:write`, `pins:read`, and `pins:write`. Keep them until a Sandbox test proves that the narrower scope set supports the complete workflow.

The contact form stores plain text in `contact_messages` and sends a text-only SMTP notification to `CONTACT_RECIPIENT_EMAIL` (default: `mauriziovalch@gmail.com`). Configure `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME`, `SMTP_PASSWORD`, and `CONTACT_FROM_EMAIL`. Store the SMTP password in Secret Manager in production.

For Cloud Run, inject `SMTP_PASSWORD` with `--set-secrets=SMTP_PASSWORD=YOUR_SECRET:latest` and the non-secret values with `--set-env-vars`. Port 587 with TLS is the default. The web image installs PHPMailer during `composer install`, and the application creates `contact_messages` in Cloud SQL on first database initialization.
