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

Only after the archive is complete:

1. Add account status support and disable authentication for the test account.
2. Keep shared files active.
3. Smoke-test the platform and the active Maurizio Valch tenant.
4. Observe for an agreed retention period.
5. Remove exclusive active copies with a separate, reviewed command.
6. Hard-delete database rows only after a restore test and an explicit approval.

Archiving and deletion intentionally are not combined in one command.
