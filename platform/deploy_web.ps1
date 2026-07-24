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
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$BuildConfig = Join-Path $PSScriptRoot "cloudbuild.cached-image.yaml"

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

Write-Host "Deploying mockups-web only..." -ForegroundColor Cyan

# Cloud Build workers are ephemeral. Pull the previous Artifact Registry image
# and use its layers as a remote Docker cache so PHP, Python and Composer are
# rebuilt only when their inputs change.
Invoke-Gcloud builds submit `
    --project=$ProjectId `
    "--config=$BuildConfig" `
    "--substitutions=_IMAGE=$imageBase/$WebService,_DOCKERFILE=platform/Dockerfile.web" `
    $RepoRoot

$digest = Get-GcloudValue artifacts docker images describe "$imageBase/${WebService}:latest" `
    --project=$ProjectId `
    "--format=value(image_summary.digest)"
if (-not $digest.StartsWith("sha256:")) {
    throw "Could not resolve the immutable image digest for ${WebService}:latest. Received: $digest"
}
$imageRef = "$imageBase/${WebService}@$digest"
$revisionSuffix = "d" + (Get-Date).ToUniversalTime().ToString("yyyyMMddHHmmss")
$revision = "$WebService-$revisionSuffix"

Invoke-Gcloud run deploy $WebService `
    --project=$ProjectId `
    --image=$imageRef `
    --revision-suffix=$revisionSuffix `
    --allow-unauthenticated `
    --service-account="mockups-web-sa@$ProjectId.iam.gserviceaccount.com" `
    --cpu=2 `
    --memory=4Gi `
    --concurrency=20 `
    --min-instances=1 `
    --max-instances=10 `
    --timeout=300 `
    --region=$Region

# A service may retain revision-pinned traffic after a rollback. Verify and
# route the exact named revision created by this deployment instead of relying
# on Cloud Run's latestReady traffic pointer.
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
Invoke-Gcloud run services update-traffic $WebService `
    --project=$ProjectId `
    --region=$Region `
    --to-revisions="${revision}=100"

Write-Host "SUCCESS: mockups-web deployed as $revision ($digest)." -ForegroundColor Green
