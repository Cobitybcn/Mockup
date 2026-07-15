param(
    [string]$Bucket = "project-ff549db7-4f7f-4b0c-9a5-artwork-mockups-storage",
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$source = Join-Path $repoRoot "storage\world_mothers"

if (-not (Test-Path -LiteralPath $source -PathType Container)) {
    throw "Local storage\world_mothers directory was not found: $source"
}

$destination = "gs://$Bucket/storage/world_mothers"
$args = @("storage", "rsync", "-r")
if ($DryRun) {
    $args += "--dry-run"
}
$args += @($source, $destination)

Write-Host "Syncing $source -> $destination"
& gcloud @args
