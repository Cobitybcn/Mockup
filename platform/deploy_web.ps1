param(
    [string]$ProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$Region = "us-central1",
    [string]$Repository = "mockups-repo",
    [string]$WebService = "mockups-web"
)

# Deploy only the Cloud Run web service.
# Use this for PHP/UI fixes that do not require rebuilding the worker.

$ErrorActionPreference = "Stop"
$imageBase = "$Region-docker.pkg.dev/$ProjectId/$Repository"
$Gcloud = "gcloud.cmd"

function Invoke-Gcloud {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$Arguments
    )

    # Windows PowerShell ISE turns normal gcloud stderr progress into
    # NativeCommandError records. The native exit code is authoritative.
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        & $Gcloud @Arguments
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($exitCode -ne 0) {
        throw "gcloud failed with exit code ${exitCode}: gcloud $($Arguments -join ' ')"
    }
}

Write-Host "Deploying mockups-web only..." -ForegroundColor Cyan

Copy-Item Dockerfile.web Dockerfile -Force
try {
    Invoke-Gcloud builds submit --project=$ProjectId --tag="$imageBase/${WebService}:latest" .
} finally {
    if (Test-Path Dockerfile) {
        Remove-Item Dockerfile -Force
    }
}

Invoke-Gcloud run deploy $WebService `
    --project=$ProjectId `
    --image="$imageBase/${WebService}:latest" `
    --allow-unauthenticated `
    --service-account="mockups-web-sa@$ProjectId.iam.gserviceaccount.com" `
    --min-instances=0 `
    --max-instances=2 `
    --memory=2Gi `
    --region=$Region

# The service can retain revision-pinned traffic from a previous rollback.
# Explicitly route traffic to the newest healthy revision after deployment.
Invoke-Gcloud run services update-traffic $WebService `
    --project=$ProjectId `
    --region=$Region `
    --to-latest

Write-Host "SUCCESS: mockups-web deployed." -ForegroundColor Green
