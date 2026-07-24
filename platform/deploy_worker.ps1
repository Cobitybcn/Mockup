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
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$BuildConfig = Join-Path $PSScriptRoot "cloudbuild.cached-image.yaml"

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

function Get-GcloudValue {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$Arguments
    )

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        $rawLines = @(& $Gcloud @Arguments)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($exitCode -ne 0) {
        throw "gcloud failed with exit code ${exitCode}: gcloud $($Arguments -join ' ')"
    }

    $lines = @(@($rawLines) | Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) })
    if ($lines.Count -eq 0) {
        throw "gcloud returned no value: gcloud $($Arguments -join ' ')"
    }

    return ([string]$lines[-1]).Trim()
}

Write-Host "Deploying mockups-worker only..." -ForegroundColor Cyan

# Reuse the previous Artifact Registry image as a remote Docker cache. This is
# especially valuable for the worker's FFmpeg, PHP extension and Python layers.
Invoke-Gcloud builds submit `
    --project=$ProjectId `
    "--config=$BuildConfig" `
    "--substitutions=_IMAGE=$imageBase/$WorkerService,_DOCKERFILE=platform/Dockerfile.worker" `
    $RepoRoot

$digest = Get-GcloudValue artifacts docker images describe "$imageBase/${WorkerService}:latest" `
    --project=$ProjectId `
    "--format=value(image_summary.digest)"
if (-not $digest.StartsWith("sha256:")) {
    throw "Could not resolve the immutable image digest for ${WorkerService}:latest. Received: $digest"
}
$imageRef = "$imageBase/${WorkerService}@$digest"
$revisionSuffix = "d" + (Get-Date).ToUniversalTime().ToString("yyyyMMddHHmmss")
$revision = "$WorkerService-$revisionSuffix"

Invoke-Gcloud run deploy $WorkerService `
    --project=$ProjectId `
    --image=$imageRef `
    --revision-suffix=$revisionSuffix `
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

$revisionReady = Get-GcloudValue run revisions describe $revision `
    --project=$ProjectId `
    --region=$Region `
    "--format=value(status.conditions[0].status)"
if ($revisionReady -ne "True") {
    throw "Revision $revision is not ready. Ready status: $revisionReady"
}
$revisionImage = Get-GcloudValue run revisions describe $revision `
    --project=$ProjectId `
    --region=$Region `
    "--format=value(spec.containers[0].image)"
if (-not $revisionImage.EndsWith("@$digest")) {
    throw "Latest ready revision $revision does not use the image built by this deployment. Expected digest: $digest; image: $revisionImage"
}
Invoke-Gcloud run services update-traffic $WorkerService `
    --project=$ProjectId `
    --region=$Region `
    --to-revisions="${revision}=100"

Write-Host "SUCCESS: mockups-worker deployed as $revision ($digest)." -ForegroundColor Green
