# Admin V2 deployment

Remote root: `/public/maurizio-website-new`

Upload the contents of `deploy/admin-v2/` preserving its directory structure. Do not upload the local `.env`.

Create or update `/public/maurizio-website-new/.env` directly on the server with:

```env
ARTWORK_SYNC_SHARED_SECRET=replace-with-at-least-32-random-characters
```

Use the same value in Artwork Mockups. This secret authenticates editorial synchronization and must not be committed, logged or shown in either interface.

Initial verification:

1. Open `/admin-v2/` and sign in with the existing website admin password.
2. Confirm that the V2 catalogue is empty.
3. Send one controlled editorial fixture.
4. Set its commercial status and visibility in Admin V2.
5. Confirm that resending editorial content preserves those commercial values.

The package intentionally excludes `content.json`, the legacy catalogue, uploaded artwork media and all credentials.
# Automatic SFTP deploy

FileZilla is not required. From the project root:

```powershell
.\scripts\deploy_admin_v2_sftp.ps1 scan-host-key
.\scripts\deploy_admin_v2_sftp.ps1 dry-run
.\scripts\deploy_admin_v2_sftp.ps1 deploy
```

The deploy reads SFTP credentials from `.env`, uploads only manifest files, verifies
SHA-256 after upload, and never deletes remote files.
