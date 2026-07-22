# Artwork Mockups

This repository keeps the application and its deployed artist site together:

- `platform/` contains the application, workers, migrations, and tests.
- `artist-site/` contains the Maurizio Valch site deployment and its editorial content.

Runtime data, local credentials, generated media, caches, and recovery bundles are intentionally excluded from Git. Binary artwork assets are stored through Git LFS.

## Local development

The active local environment is identified by `APP_ENV=local` and uses the clean `artwork_mockups_local_v2` database configured in the ignored `platform/.env` file. The application shows a permanent `LOCAL` badge and refuses to connect a local runtime to a database whose name does not contain `local`.

Retired database copies and user archives under `C:\laragon\archives\artworkmockups` are recovery material only; they are not application data sources. Local feature work must stay on a branch other than `main`. Only a push to GitHub's `main` branch can activate the production delivery workflow described below.

## Production CI/CD

Artwork Mockups uses two path-filtered Google Cloud Build triggers on GitHub's `main` branch:

- changes under `platform/` or `site-admin/` run `platform/cloudbuild.ci.yaml`;
- changes under `artist-site/` run `artist-site/cloudbuild.hardening.yaml`;
- documentation, runtime uploads, and unrelated design references do not start production builds.

The application pipeline:

1. verifies the Google Cloud project, production branch, Artifact Registry repository, Cloud Run services, and runtime service accounts;
2. builds `platform/Dockerfile.web` and, when shared backend code changed, `platform/Dockerfile.worker`;
3. runs `platform/tests/run_regression_tests.php` inside the web image;
4. pushes the built commit-addressed images to the existing `mockups-repo` Artifact Registry repository;
5. when schema files changed, runs the same immutable image as the `mockups-db-migrate` Cloud Run Job and stops the release if the production database cannot reach the exact schema version shipped by that commit;
6. deploys the worker when required and then the web service by immutable digest, initially with no traffic, before routing each service to the verified revision.

Each web revision records its immutable Git commit. Later releases compare against that commit: web-only changes skip the worker, and the database migration job runs only when `platform/migrations/schema/` changed. The first release without a recorded baseline intentionally runs the complete pipeline. The artist-site pipeline reuses its previous production image as a Docker cache, smoke-tests a no-traffic candidate, and promotes it only after verification.

The deploy commands preserve secrets, runtime identities, access policies, and scaling settings. They explicitly enforce `APP_ENV=production` and the public product feature flags on every revision. Sensitive values remain in Secret Manager and are never stored in this repository.

## Database schema governance

Localhost and production use separate data, but they share the same ordered schema history in `platform/migrations/schema/`. `schema_migrations` records the version and SHA-256 checksum of every applied migration. Startup applies pending additive migrations under a database lock; a changed or missing historical migration fails closed.

Use these commands to inspect the local database without comparing user data:

```powershell
php platform/scripts/database_schema_status.php
php platform/scripts/database_schema_status.php --assert-current --json
```

Every future table, column, index, or constraint change must be a new immutable file in `platform/migrations/schema/`. Applied migration files are never edited or deleted. Production delivery performs this check before either Cloud Run service receives traffic.

The one-time setup is implemented in `platform/scripts/setup_cloud_build_cicd.ps1`. It verifies that GitHub's default branch is `main` and that the destination services are the existing `mockups-web` and `mockups-worker` services in `us-central1`. It then creates a dedicated least-privilege build identity and the `artwork-mockups-main-deploy` trigger. The setup script does not start a build or deploy.

Before the trigger can be created, authorize the Cloud Build GitHub App for the `Cobitybcn/Mockup` repository from the Cloud Build **Connect repository** screen. This is an interactive GitHub authorization and is not stored in the repository. After connecting it, run:

```powershell
.\platform\scripts\setup_cloud_build_cicd.ps1
```

The script is safe to rerun: it reuses the dedicated service account and IAM bindings, and creates or updates both path-filtered triggers without starting a deployment.

## Artist media ownership

The live artist site reads published images through Artwork Mockups media endpoints. Generated uploads and tenant media are runtime data, not source code, and are excluded from Git and both build contexts. The historical pre-platform media snapshot belongs in the external recovery archive, not under `artist-site/assets/uploads/`.
