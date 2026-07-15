# Retiring a test account safely

Test accounts can own database records and media that are still referenced by another account. Retirement therefore happens in separate, reversible stages.

## 1. Audit

From `platform/`:

```powershell
php scripts/archive_user.php --email=test-account@example.com
```

This is the default dry-run mode. It reads the database and filesystem, reports the rows and files associated with the account, and separates files that are still referenced elsewhere. It does not write, move, or delete anything.

## 2. Copy and verify

Use a new archive directory outside the Git repository and deployment context:

```powershell
php scripts/archive_user.php `
  --email=test-account@example.com `
  --archive-dir=C:\laragon\archives\artworkmockups\retired-users\user-42-YYYYMMDD `
  --copy
```

The archive contains:

- `manifest.json`: user, database counts, copied files, and shared files kept active.
- `database-export.sql`: account-owned rows and dependent rows.
- `checksums.sha256`: SHA-256 checksums for the database export and every copied file.
- `files/`: copies preserving their original paths under the platform.

The command verifies every copy against its source. It never changes the source database or files.

The archive contains password hashes and may contain integration metadata. Keep it private and outside Git.

## 3. Disable, observe, and remove later

Only after the archive is complete, first preview the reversible status change:

```powershell
php scripts/set_user_status.php `
  --email=test-account@example.com `
  --status=disabled `
  --archive-manifest=C:\laragon\archives\artworkmockups\retired-users\user-42-YYYYMMDD\manifest.json
```

Apply it only after the preview identifies the expected user and archive:

```powershell
php scripts/set_user_status.php `
  --email=test-account@example.com `
  --status=disabled `
  --archive-manifest=C:\laragon\archives\artworkmockups\retired-users\user-42-YYYYMMDD\manifest.json `
  --execute
```

Disabled accounts cannot log in, use existing sessions, or request/reset a password. Their rows and files remain intact. Reactivation is explicit and does not require restoring the archive:

```powershell
php scripts/set_user_status.php --email=test-account@example.com --status=active --execute
```

Then:

1. Keep shared files active.
2. Smoke-test the platform and the active Maurizio Valch tenant.
3. Observe for an agreed retention period.
4. Remove exclusive active copies with a separate, reviewed command.
5. Hard-delete database rows only after a restore test and an explicit approval.

Archiving and deletion intentionally are not combined in one command.

## 4. Permanent purge

After an explicit approval, the verified archive can drive a permanent purge of the disabled account and its exclusive active files:

```powershell
php scripts/purge_archived_user.php `
  --email=test-account@example.com `
  --archive-manifest=C:\laragon\archives\artworkmockups\retired-users\user-42-YYYYMMDD\manifest.json `
  --execute `
  --confirm=DELETE
```

The purge keeps files that are referenced by another account and does not delete the archive itself.
