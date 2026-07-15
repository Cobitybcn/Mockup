param(
    [string]$ProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$Region = "us-central1",
    [string]$Repository = "mockups-repo",
    [string]$WorkerService = "mockups-worker",
    [string]$TasksInvokerServiceAccountName = "mockups-tasks-invoker-sa"
)

# Deploy only the Cloud Run worker service.
# Use this for generation, Vertex bridge, Python, Composer, or worker endpoint fixes.

$ErrorActionPreference = "Stop"
$imageBase = "$Region-docker.pkg.dev/$ProjectId/$Repository"
$Gcloud = "gcloud.cmd"

function Invoke-Gcloud {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$Arguments
    )

    # Windows PowerShell ISE converts normal gcloud stderr progress messages
    # into NativeCommandError records. Do not treat those records as failures;
    # gcloud's process exit code is the authoritative result.
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

Write-Host "Deploying mockups-worker only..." -ForegroundColor Cyan

Copy-Item Dockerfile.worker Dockerfile -Force
try {
    Invoke-Gcloud builds submit --project=$ProjectId --tag="$imageBase/${WorkerService}:latest" .
} finally {
    if (Test-Path Dockerfile) {
        Remove-Item Dockerfile -Force
    }
}

Invoke-Gcloud run deploy $WorkerService `
    --project=$ProjectId `
    --image="$imageBase/${WorkerService}:latest" `
    --no-allow-unauthenticated `
    --service-account="mockups-worker-sa@$ProjectId.iam.gserviceaccount.com" `
    --min-instances=0 `
    --max-instances=2 `
    --memory=2Gi `
    --region=$Region

Invoke-Gcloud run services add-iam-policy-binding $WorkerService `
    --project=$ProjectId `
    --region=$Region `
    --member="serviceAccount:$TasksInvokerServiceAccountName@$ProjectId.iam.gserviceaccount.com" `
    --role="roles/run.invoker"

Write-Host "SUCCESS: mockups-worker deployed." -ForegroundColor Green
