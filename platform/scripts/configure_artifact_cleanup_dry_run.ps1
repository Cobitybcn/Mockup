param(
    [string]$ProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$Region = "us-central1",
    [string]$Repository = "mockups-repo"
)

$ErrorActionPreference = "Stop"
$expectedProject = "project-ff549db7-4f7f-4b0c-9a5"
$expectedRegion = "us-central1"
$expectedRepository = "mockups-repo"

if ($ProjectId -ne $expectedProject -or $Region -ne $expectedRegion -or $Repository -ne $expectedRepository) {
    throw "Safety stop: cleanup dry-run target does not match the production Artifact Registry repository."
}

$policy = (Resolve-Path (Join-Path $PSScriptRoot "..\config\artifact-registry-cleanup-policy.json")).Path
& gcloud.ps1 artifacts repositories describe $Repository `
    --project=$ProjectId `
    --location=$Region `
    "--format=value(name)"
if ($LASTEXITCODE -ne 0) {
    throw "Could not verify Artifact Registry repository."
}

& gcloud.ps1 artifacts repositories set-cleanup-policies $Repository `
    --project=$ProjectId `
    --location=$Region `
    --policy=$policy `
    --dry-run `
    --quiet
if ($LASTEXITCODE -ne 0) {
    throw "Could not configure Artifact Registry cleanup dry-run."
}

Write-Host "SUCCESS: cleanup policy is in dry-run mode. No artifact deletion is enabled." -ForegroundColor Green
Write-Host "Review validateOnly audit logs after at least 24 hours before considering an active policy." -ForegroundColor Yellow
