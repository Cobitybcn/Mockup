# Direct Instagram connection

Artwork Mockups uses **Instagram API with Instagram Login** for the artist account. It is separate from the Facebook Page connection and does not require linking that Instagram account to the Facebook Page.

## Meta dashboard

- Use case: Manage messages and content on Instagram.
- Account: professional Creator or Business account.
- Valid OAuth redirect URI: `https://artworkmockups.com/integrations/instagram/callback`
- Deauthorization callback URL: `https://artworkmockups.com/integrations/instagram/deauthorize/`
- Data deletion request URL: `https://artworkmockups.com/integrations/instagram/data-deletion/`
- Requested permissions:
  - `instagram_business_basic`
  - `instagram_business_content_publish`

## Runtime configuration

The app ID and app secret shown by the Instagram API setup belong in `INSTAGRAM_APP_ID_ARTIST` and `INSTAGRAM_APP_SECRET_ARTIST`. The secret and `INSTAGRAM_TOKEN_KEY` must be stored in Secret Manager, never in source control.

Keep `INSTAGRAM_OAUTH_ENABLED=false` until the public callback and credentials are configured. Keep `INSTAGRAM_LIVE_PUBLISH_ENABLED=false` until the account connection is verified and a controlled publication test is explicitly approved.

Tokens are encrypted independently from Facebook tokens in the `instagram_connections` table.

## Publication separation

- Facebook drafts use the Facebook Page token and `graph.facebook.com`.
- Instagram drafts use the direct Instagram token and `graph.instagram.com`.
- Instagram publication requires both `INSTAGRAM_LIVE_PUBLISH_ENABLED=true` and `INSTAGRAM_DRAFT_PUBLIC_MEDIA_ENABLED=true`.
- The media URL is time-limited and unguessable. A final POST, CSRF token, confirmation checkbox and the exact word `PUBLICAR` are still required.
- An inconclusive transport response is recorded as `needs_verification`; it is never retried automatically because the first request may already have created a post.
