# Pinterest integration preparation

OAuth, encrypted renewable tokens, board selection, explicit confirmation, and Pin creation are implemented. Apply migrations `000001` and `000002` in order. Existing administrator connections become `platform`; existing non-administrator connections become `artist`. Administrators may then connect a separate artist account.

Required environment variables are `PINTEREST_APP_ID`, `PINTEREST_APP_SECRET`, and `PINTEREST_REDIRECT_URI`. The app secret is backend-only and must not be committed, rendered, logged, or sent in an error. In Google Cloud production, load `PINTEREST_APP_SECRET` from Secret Manager rather than a deployed environment file.

OAuth state is random, session-bound, single-use, and expires after ten minutes. Tokens use authenticated encryption and refresh automatically. Every Pin requires a fresh explicit confirmation. `artist` connections are used for artwork content; `platform` is reserved for administrator-led promotion of Artwork Mockups.

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
