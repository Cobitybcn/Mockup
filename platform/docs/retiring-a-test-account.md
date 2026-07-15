# Retiring a test account safely

Test accounts can own database records and media that are still referenced by another account. Retirement therefore happens in separate, reversible stages.

## 1. Audit

From `platform/`:

```powershell
php scripts/archive_user.php --email=chiappero@gmail.com
```

This is the default dry-run mode. It reads the database and filesystem, reports the rows and files associated with the account, and separates files that are still referenced elsewhere. It does not write, move, or delete anything.

## 2. Copy and verify

Use a new archive directory outside the Git repository and deployment context:

```powershell
php scripts/archive_user.php `
  --email=chiappero@gmail.com `
  --archive-dir=C:\laragon\archives\artworkmockups\retired-users\user-1-20260715 `
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
  --email=chiappero@gmail.com `
  --status=disabled `
  --archive-manifest=C:\laragon\archives\artworkmockups\retired-users\user-1-20260715\manifest.json
```

Apply it only after the preview identifies the expected user and archive:

```powershell
php scripts/set_user_status.php `
  --email=chiappero@gmail.com `
  --status=disabled `
  --archive-manifest=C:\laragon\archives\artworkmockups\retired-users\user-1-20260715\manifest.json `
  --execute
```

Disabled accounts cannot log in, use existing sessions, or request/reset a password. Their rows and files remain intact. Reactivation is explicit and does not require restoring the archive:

```powershell
php scripts/set_user_status.php --email=chiappero@gmail.com --status=active --execute
```

Then:

1. Keep shared files active.
2. Smoke-test the platform and the active Maurizio Valch tenant.
3. Observe for an agreed retention period.
4. Remove exclusive active copies with a separate, reviewed command.
5. Hard-delete database rows only after a restore test and an explicit approval.

Archiving and deletion intentionally are not combined in one command.
