param(
    [string]$ProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$Region = "us-central1",
    [string]$Repository = "mockups-repo",
    [string]$WebService = "mockups-web",
    [string]$WorkerService = "mockups-worker",
    [string]$WebRuntimeServiceAccountName = "mockups-web-sa",
    [string]$WorkerRuntimeServiceAccountName = "mockups-worker-sa",
    [string]$CicdServiceAccountName = "mockups-cicd-sa",
    [string]$GitHubOwner = "Cobitybcn",
    [string]$GitHubRepository = "Mockup",
    [string]$ProductionBranch = "main",
    [string]$TriggerName = "artwork-mockups-main-deploy"
)

$ErrorActionPreference = "Stop"
# Use the PowerShell launcher so regex anchors such as ^main$ are not consumed
# by cmd.exe while creating the trigger.
$Gcloud = "gcloud.ps1"
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$buildConfig = "platform/cloudbuild.ci.yaml"
$cicdServiceAccount = "$CicdServiceAccountName@$ProjectId.iam.gserviceaccount.com"
$cicdServiceAccountResource = "projects/$ProjectId/serviceAccounts/$cicdServiceAccount"

function Invoke-Gcloud {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$Arguments
    )

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        $null = & $Gcloud @Arguments
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
        $lines = @(& $Gcloud @Arguments)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($exitCode -ne 0) {
        throw "gcloud failed with exit code ${exitCode}: gcloud $($Arguments -join ' ')"
    }

    $value = @($lines) | Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) } | Select-Object -Last 1
    if ($null -eq $value) {
        return ""
    }

    return ([string]$value).Trim()
}

if ($ProductionBranch -ne "main") {
    throw "Safety stop: the production branch must be main. Received: $ProductionBranch"
}
if (-not (Test-Path -LiteralPath (Join-Path $repoRoot "platform\cloudbuild.ci.yaml"))) {
    throw "Cloud Build configuration not found: $buildConfig"
}

$originUrl = (& git -C $repoRoot remote get-url origin).Trim()
if ($LASTEXITCODE -ne 0 -or $originUrl -notmatch "github\.com[:/]$([regex]::Escape($GitHubOwner))/$([regex]::Escape($GitHubRepository))(\.git)?$") {
    throw "Safety stop: origin does not match $GitHubOwner/$GitHubRepository. Received: $originUrl"
}

$remoteHead = @(& git -C $repoRoot ls-remote --symref origin HEAD) | Select-Object -First 1
if ($LASTEXITCODE -ne 0 -or $remoteHead -notmatch "refs/heads/$([regex]::Escape($ProductionBranch))") {
    throw "Safety stop: GitHub's default branch is not $ProductionBranch. Received: $remoteHead"
}

foreach ($service in @(
    @{ Name = $WebService; RuntimeAccount = "$WebRuntimeServiceAccountName@$ProjectId.iam.gserviceaccount.com" },
    @{ Name = $WorkerService; RuntimeAccount = "$WorkerRuntimeServiceAccountName@$ProjectId.iam.gserviceaccount.com" }
)) {
    $actualRuntimeAccount = Get-GcloudValue run services describe $service.Name `
        --project=$ProjectId `
        --region=$Region `
        --platform=managed `
        "--format=value(spec.template.spec.serviceAccountName)"
    if ($actualRuntimeAccount -ne $service.RuntimeAccount) {
        throw "Safety stop: $($service.Name) uses $actualRuntimeAccount, expected $($service.RuntimeAccount)."
    }
}

Invoke-Gcloud artifacts repositories describe $Repository `
    --project=$ProjectId `
    --location=$Region `
    "--format=value(name)"

$existingServiceAccount = Get-GcloudValue iam service-accounts list `
    --project=$ProjectId `
    "--filter=email:$cicdServiceAccount" `
    "--format=value(email)"
if ([string]::IsNullOrWhiteSpace($existingServiceAccount)) {
    Invoke-Gcloud iam service-accounts create $CicdServiceAccountName `
        --project=$ProjectId `
        --display-name="Artwork Mockups CI/CD"
}

Invoke-Gcloud projects add-iam-policy-binding $ProjectId `
    --member="serviceAccount:$cicdServiceAccount" `
    --role="roles/logging.logWriter" `
    --condition=None `
    --quiet

Invoke-Gcloud projects add-iam-policy-binding $ProjectId `
    --member="serviceAccount:$cicdServiceAccount" `
    --role="roles/run.developer" `
    --condition=None `
    --quiet

Invoke-Gcloud artifacts repositories add-iam-policy-binding $Repository `
    --project=$ProjectId `
    --location=$Region `
    --member="serviceAccount:$cicdServiceAccount" `
    --role="roles/artifactregistry.writer" `
    --condition=None `
    --quiet

foreach ($runtimeAccountName in @($WebRuntimeServiceAccountName, $WorkerRuntimeServiceAccountName)) {
    Invoke-Gcloud iam service-accounts add-iam-policy-binding `
        "$runtimeAccountName@$ProjectId.iam.gserviceaccount.com" `
        --project=$ProjectId `
        --member="serviceAccount:$cicdServiceAccount" `
        --role="roles/iam.serviceAccountUser" `
        --condition=None `
        --quiet
}

$existingTriggerId = Get-GcloudValue builds triggers list `
    --project=$ProjectId `
    --region=global `
    "--filter=name=$TriggerName" `
    "--format=value(id)"
if (-not [string]::IsNullOrWhiteSpace($existingTriggerId)) {
    throw "Safety stop: trigger $TriggerName already exists ($existingTriggerId). Inspect it before changing it."
}

$branchPattern = '^' + [regex]::Escape($ProductionBranch) + '$'

try {
    Invoke-Gcloud builds triggers create github `
        --project=$ProjectId `
        --region=global `
        --name=$TriggerName `
        --description="Test and deploy Artwork Mockups to Cloud Run on pushes to main" `
        --repo-owner=$GitHubOwner `
        --repo-name=$GitHubRepository `
        --branch-pattern=$branchPattern `
        --build-config=$buildConfig `
        --service-account=$cicdServiceAccountResource `
        --include-logs-with-status `
        --no-require-approval `
        --quiet
} catch {
    Write-Host "Cloud Build could not access $GitHubOwner/$GitHubRepository." -ForegroundColor Yellow
    Write-Host "Connect that repository once at:" -ForegroundColor Yellow
    Write-Host "https://console.cloud.google.com/cloud-build/triggers;region=global/connect?project=332598630108" -ForegroundColor Yellow
    Write-Host "Then run this script again. Re-running it does not start a build or deployment." -ForegroundColor Yellow
    throw
}

Write-Host "SUCCESS: Cloud Build trigger $TriggerName is configured for $GitHubOwner/${GitHubRepository}:$ProductionBranch." -ForegroundColor Green
Write-Host "No build or deployment was started by this setup script." -ForegroundColor Green
