# Meta publication workflow

Artwork Mockups prepares Facebook and Instagram content separately and never publishes during campaign preparation. A Meta batch contains one draft per selected mockup and destination. Every draft has editable copy, hashtags, alt text, an optional HTTPS destination, and a reviewed 4:5 JPEG.

Draft claims prevent concurrent double submission. If Meta times out or returns an inconclusive transport response after a write may have reached its API, the item moves to `needs_verification` instead of retrying automatically. The review screen then requires a manual account check and the confirmation text `VERIFICADO` before recording the existing post or allowing a retry.

## Required configuration

The artist identity uses `META_APP_ID_ARTIST`, `META_APP_SECRET_ARTIST`, and `META_REDIRECT_URI_ARTIST`. The optional platform identity uses the corresponding `_PLATFORM` variables and remains administrator-only. `META_TOKEN_KEY` must contain exactly 32 random bytes encoded in Base64 and must remain stable across deployments so stored tokens remain decryptable.

The requested Facebook Login permissions are `pages_show_list`, `pages_read_engagement`, and `pages_manage_posts`. The OAuth callback records the permissions actually granted; publication is blocked if the required permission for a selected destination is absent. Instagram is connected separately so the Facebook Page authorization does not request unrelated Instagram permissions.

The Facebook user connecting the app must be able to create content on the selected Page. Instagram publication additionally requires a professional Instagram account linked to that Page.

Register the public Privacy Policy URL (`/privacy/`) and Data Deletion Instructions URL (`/data-deletion/`) in the Meta app dashboard before review. The public Terms and contact form also describe Meta publication approval and integration-data deletion requests.

## Meta dashboard values before OAuth

The current app credentials are accepted by Graph API, but the app metadata check on July 13, 2026 did not list `artworkmockups.com` in `app_domains`. Before setting `META_OAUTH_ENABLED=true`, register and verify:

- App domain: `artworkmockups.com`
- Website URL: `https://artworkmockups.com/`
- Valid OAuth redirect URI: `https://artworkmockups.com/integrations/meta/callback?purpose=artist`
- Privacy Policy: `https://artworkmockups.com/privacy/`
- Data Deletion Instructions: `https://artworkmockups.com/data-deletion/`
- Terms: `https://artworkmockups.com/terms/`

If a separate Artwork Mockups platform identity is introduced later, register its exact `_PLATFORM` redirect URI and credentials independently.

These URLs reflect the current `.env` assumption. If the application is ultimately served at `app.artworkmockups.com`, change `APP_PUBLIC_URL` and the OAuth redirect to that exact host before registering or enabling OAuth; Meta redirect URIs must match the deployed callback exactly. The legal URLs may remain on the apex only if the load balancer routes those paths to this service.

## Safety gates

Real publication requires all of the following:

1. A connected Meta identity with an unexpired token and the required granted permissions.
2. A selected Facebook Page and, for Instagram, its linked professional account.
3. `APP_PUBLIC_URL` using public HTTPS.
4. `META_DRAFT_PUBLIC_MEDIA_ENABLED=true` so Meta can fetch the short-lived random media URL.
5. `META_LIVE_PUBLISH_ENABLED=true`.
6. A valid one-time CSRF token, explicit checkbox approval, and the confirmation text `PUBLICAR`.

OAuth itself is also gated by `META_OAUTH_ENABLED=true`. Keep OAuth and both publication feature flags `false` in local development and in the first Cloud Run revision. Enable OAuth only after the exact public HTTPS callback has been registered in Meta. In production, store `META_APP_SECRET_ARTIST`, `META_APP_SECRET_PLATFORM`, and `META_TOKEN_KEY` in Secret Manager. Do not commit, render, or log them.

## First staging test

Use one mockup and a Page/account controlled by an app administrator, developer, or tester. Connect Meta, confirm the Page and Instagram username on the integration screen, prepare a batch, review both cards, then enable the public-media gate. Verify the media URL from an unauthenticated browser before enabling live publication. Publish Facebook first, verify the stored external ID/link, and only then test Instagram.

After the app serves Meta accounts outside its assigned roles, complete the applicable Meta business verification and App Review requirements before enabling publication for those users.
