param(
    [string]$ProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$CloudSqlProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$Region = "us-central1",
    [string]$VertexProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$VertexLocation = "global",
    [string]$InstanceName = "mockups-mysql",
    [string]$DbDatabase = "mockups",
    [string]$DbUsername = "mockups_app",
    [string]$GeminiImageModel = "gemini-3.1-flash-image",
    [SecureString]$DbPassword
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($VertexProjectId)) {
    $VertexProjectId = $ProjectId
}
if ([string]::IsNullOrWhiteSpace($CloudSqlProjectId)) {
    $CloudSqlProjectId = $ProjectId
}

function Convert-SecureStringToPlainText {
    param([SecureString]$Secure)

    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Secure)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
}

if (-not $DbPassword) {
    $DbPassword = Read-Host "Enter a password for the Cloud SQL MySQL user '$DbUsername'" -AsSecureString
}

$plainPassword = Convert-SecureStringToPlainText $DbPassword
if ([string]::IsNullOrWhiteSpace($plainPassword)) {
    throw "Database password cannot be empty for Cloud SQL."
}

$instanceConnectionName = "${CloudSqlProjectId}:${Region}:${InstanceName}"

Write-Host "Using Cloud Run project $ProjectId" -ForegroundColor Cyan
gcloud config set project $ProjectId

Write-Host "Enabling required Google Cloud APIs on Cloud Run project..." -ForegroundColor Cyan
gcloud services enable run.googleapis.com cloudbuild.googleapis.com artifactregistry.googleapis.com --project=$ProjectId

Write-Host "Enabling Cloud SQL API on SQL project $CloudSqlProjectId..." -ForegroundColor Cyan
gcloud services enable sqladmin.googleapis.com --project=$CloudSqlProjectId

Write-Host "Checking Cloud SQL instance $InstanceName..." -ForegroundColor Cyan
$existingInstance = gcloud sql instances describe $InstanceName --project=$CloudSqlProjectId --format="value(name)" 2>$null
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($existingInstance)) {
    Write-Host "Creating Cloud SQL MySQL instance $InstanceName..." -ForegroundColor Cyan
    gcloud sql instances create $InstanceName `
        --project=$CloudSqlProjectId `
        --database-version=MYSQL_8_0 `
        --region=$Region `
        --tier=db-custom-1-3840 `
        --storage-type=SSD `
        --storage-size=10GB `
        --storage-auto-increase `
        --availability-type=regional `
        --backup-start-time=03:00 `
        --enable-bin-log `
        --retained-backups-count=14 `
        --retained-transaction-log-days=7 `
        --connector-enforcement=REQUIRED `
        --deletion-protection `
        --edition=ENTERPRISE
} else {
    Write-Host "Cloud SQL instance already exists: $existingInstance" -ForegroundColor Yellow
    Write-Host "Applying non-disruptive database protection controls..." -ForegroundColor Cyan
    gcloud sql instances patch $InstanceName `
        --project=$CloudSqlProjectId `
        --backup-start-time=03:00 `
        --enable-bin-log `
        --retained-backups-count=14 `
        --retained-transaction-log-days=7 `
        --storage-auto-increase `
        --connector-enforcement=REQUIRED `
        --deletion-protection `
        --quiet
}

Write-Host "Ensuring database $DbDatabase exists..." -ForegroundColor Cyan
$existingDatabase = gcloud sql databases describe $DbDatabase --instance=$InstanceName --project=$CloudSqlProjectId --format="value(name)" 2>$null
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($existingDatabase)) {
    gcloud sql databases create $DbDatabase --instance=$InstanceName --project=$CloudSqlProjectId
}

Write-Host "Ensuring database user $DbUsername exists..." -ForegroundColor Cyan
$existingUser = gcloud sql users list --instance=$InstanceName --project=$CloudSqlProjectId --format="value(name)" | Select-String -Pattern "^$([regex]::Escape($DbUsername))$" -Quiet
if ($existingUser) {
    gcloud sql users set-password $DbUsername --instance=$InstanceName --project=$CloudSqlProjectId --password=$plainPassword
} else {
    gcloud sql users create $DbUsername --instance=$InstanceName --project=$CloudSqlProjectId --password=$plainPassword
}

Write-Host "Granting Cloud SQL Client role to Cloud Run service accounts on SQL project..." -ForegroundColor Cyan
$cloudRunServiceAccounts = @(
    "serviceAccount:mockups-web-sa@$ProjectId.iam.gserviceaccount.com",
    "serviceAccount:mockups-worker-sa@$ProjectId.iam.gserviceaccount.com"
)
foreach ($member in $cloudRunServiceAccounts) {
    gcloud projects add-iam-policy-binding $CloudSqlProjectId `
        --member=$member `
        --role="roles/cloudsql.client" `
        --condition=None
}

Write-Host "Configuring Cloud Run web and worker runtime environment..." -ForegroundColor Cyan
& "$PSScriptRoot\set_cloudrun_runtime_env.ps1" `
    -ProjectId $ProjectId `
    -Region $Region `
    -VertexProjectId $VertexProjectId `
    -VertexLocation $VertexLocation `
    -CloudSqlInstance $instanceConnectionName `
    -DbDatabase $DbDatabase `
    -DbUsername $DbUsername `
    -DbPassword $plainPassword `
    -GeminiImageModel $GeminiImageModel

Write-Host "SUCCESS: Cloud SQL is ready and Cloud Run is configured." -ForegroundColor Green
Write-Host "INSTANCE_CONNECTION_NAME: $instanceConnectionName" -ForegroundColor Green
