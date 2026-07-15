param(
    [string]$LoginHint = "paulcotyeditor@gmail.com",
    [string]$QuotaProject = "project-ff549db7-4f7f-4b0c-9a5"
)

$ErrorActionPreference = "Stop"

$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$StorageDir = Join-Path $ProjectRoot "storage"
$CloudSdkConfig = Join-Path $StorageDir "gcloud_config"
$AdcSource = Join-Path $CloudSdkConfig "application_default_credentials.json"
$AdcTarget = Join-Path $StorageDir "credentials.json"

if (-not (Get-Command gcloud -ErrorAction SilentlyContinue)) {
    throw "gcloud CLI is not available in PATH."
}

New-Item -ItemType Directory -Force -Path $CloudSdkConfig | Out-Null
$env:CLOUDSDK_CONFIG = $CloudSdkConfig

Write-Host "Using CLOUDSDK_CONFIG=$CloudSdkConfig"
Write-Host "Logging in Application Default Credentials. Choose $LoginHint in the browser."

gcloud auth application-default login `
    --scopes="https://www.googleapis.com/auth/cloud-platform"

if ($QuotaProject -ne "") {
    Write-Host "Setting ADC quota project to $QuotaProject"
    gcloud auth application-default set-quota-project $QuotaProject
}

if (-not (Test-Path $AdcSource)) {
    throw "ADC file was not created at $AdcSource"
}

if (Test-Path $AdcTarget) {
    $Backup = Join-Path $StorageDir ("credentials.backup-" + (Get-Date -Format "yyyyMMdd-HHmmss") + ".json")
    Copy-Item $AdcTarget $Backup
    Write-Host "Backed up previous credentials to $Backup"
}

Copy-Item $AdcSource $AdcTarget -Force
Write-Host "Updated $AdcTarget"
Write-Host "Vertex generation will now use ADC from $LoginHint when running locally."
