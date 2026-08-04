param(
    [string]$ProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$Region = "us-central1",
    [string]$Repository = "mockups-repo",
    [string]$ServiceName = "artworkmockups-faithful-staging",
    [string]$ServiceAccount = "artwork-next-stg-sa@project-ff549db7-4f7f-4b0c-9a5.iam.gserviceaccount.com",
    [string]$SqlInstance = "artwork-next-stg-mysql",
    [string]$SqlConnectionName = "project-ff549db7-4f7f-4b0c-9a5:us-central1:artwork-next-stg-mysql",
    [Parameter(Mandatory = $true)][string]$DatabaseName,
    [Parameter(Mandatory = $true)][string]$DatabaseUser,
    [Parameter(Mandatory = $true)][string]$DatabasePasswordSecret,
    [string]$OpenAISecret = "artwork-next-stg-openai-key"
)

$ErrorActionPreference = "Stop"
$Gcloud = "gcloud.cmd"

if ($ServiceName -notmatch 'staging') {
    throw "Safety stop: ServiceName must contain 'staging'."
}
if ($ServiceName -in @('mockups-web', 'artwork-platform-next-staging')) {
    throw "Safety stop: existing production and Platform Next services are not valid Faithful staging targets."
}
if ($DatabaseName -eq 'mockups') {
    throw "Safety stop: the production database 'mockups' cannot be used for Faithful staging."
}

function Invoke-Gcloud {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments)
    & $Gcloud @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "gcloud failed with exit code $LASTEXITCODE."
    }
}

$databaseExists = gcloud.cmd sql databases list `
    --project=$ProjectId `
    --instance=$SqlInstance `
    --filter="name=$DatabaseName" `
    --format="value(name)"
if ($LASTEXITCODE -ne 0 -or $databaseExists -ne $DatabaseName) {
    throw "The isolated staging database '$DatabaseName' does not exist on '$SqlInstance'. Create or restore it explicitly before deployment."
}

Invoke-Gcloud secrets describe $DatabasePasswordSecret --project=$ProjectId --format="value(name)"
Invoke-Gcloud secrets describe $OpenAISecret --project=$ProjectId --format="value(name)"

$tag = Get-Date -Format "yyyyMMdd-HHmmss"
$image = "$Region-docker.pkg.dev/$ProjectId/$Repository/artworkmockups-faithful-staging:$tag"
$envVars = @(
    'APP_MODE=mock',
    'ALLOW_REAL_API=false',
    'DB_CONNECTION=mysql',
    "DB_SOCKET=/cloudsql/$SqlConnectionName",
    "DB_DATABASE=$DatabaseName",
    "DB_USERNAME=$DatabaseUser",
    'DB_CHARSET=utf8mb4',
    'ASSISTANT_ENABLED=true',
    'ASSISTANT_ADMIN_ENABLED=true',
    'ASSISTANT_APP_ENABLED=true',
    'OPENAI_ASSISTANT_MODEL=gpt-5.6-terra',
    'OPENAI_API_BASE=https://api.openai.com/v1',
    'PINTEREST_LIVE_PUBLISH_ENABLED=false',
    'META_LIVE_PUBLISH_ENABLED=false',
    'INSTAGRAM_LIVE_PUBLISH_ENABLED=false'
) -join ','
$secrets = "DB_PASSWORD=${DatabasePasswordSecret}:latest,OPENAI_API_KEY=${OpenAISecret}:latest"

Copy-Item Dockerfile.web Dockerfile -Force
try {
    Invoke-Gcloud builds submit --project=$ProjectId --tag=$image .
} finally {
    if (Test-Path Dockerfile) {
        Remove-Item Dockerfile -Force
    }
}

Invoke-Gcloud run deploy $ServiceName `
    --project=$ProjectId `
    --region=$Region `
    --image=$image `
    --service-account=$ServiceAccount `
    --add-cloudsql-instances=$SqlConnectionName `
    --set-env-vars=$envVars `
    --set-secrets=$secrets `
    --allow-unauthenticated `
    --min-instances=0 `
    --max-instances=1 `
    --memory=2Gi

Write-Host "Faithful staging deployed to the isolated service '$ServiceName'." -ForegroundColor Green
