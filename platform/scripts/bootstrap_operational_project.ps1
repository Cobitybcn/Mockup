param(
    [string]$ProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$Region = "us-central1",
    [string]$Repository = "mockups-repo",
    [string]$Bucket = "project-ff549db7-4f7f-4b0c-9a5-artwork-mockups-storage",
    [string]$QueueName = "mockups-generation-queue",
    [string]$WebServiceAccountName = "mockups-web-sa",
    [string]$WorkerServiceAccountName = "mockups-worker-sa",
    [string]$TasksInvokerServiceAccountName = "mockups-tasks-invoker-sa"
)

$ErrorActionPreference = "Stop"

function Ensure-ServiceAccount {
    param(
        [string]$ProjectId,
        [string]$AccountName,
        [string]$DisplayName
    )

    $email = "$AccountName@$ProjectId.iam.gserviceaccount.com"
    $existing = gcloud iam service-accounts describe $email --project=$ProjectId --format="value(email)" 2>$null
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($existing)) {
        Write-Host "Creating service account $email..." -ForegroundColor Cyan
        gcloud iam service-accounts create $AccountName `
            --project=$ProjectId `
            --display-name=$DisplayName
    } else {
        Write-Host "Service account already exists: $email" -ForegroundColor Yellow
    }

    return $email
}

function Ensure-ProjectRole {
    param(
        [string]$ProjectId,
        [string]$Member,
        [string]$Role
    )

    Write-Host "Granting $Role to $Member on $ProjectId..." -ForegroundColor Cyan
    gcloud projects add-iam-policy-binding $ProjectId `
        --member=$Member `
        --role=$Role `
        --condition=None | Out-Null
}

function Wait-ForEnabledService {
    param(
        [string]$ProjectId,
        [string]$ServiceName,
        [int]$Attempts = 24,
        [int]$DelaySeconds = 5
    )

    for ($i = 1; $i -le $Attempts; $i++) {
        $state = gcloud services list `
            --enabled `
            --project=$ProjectId `
            --filter="config.name=$ServiceName" `
            --format="value(config.name)" 2>$null

        if ($state -eq $ServiceName) {
            Write-Host "API enabled: $ServiceName" -ForegroundColor Green
            return
        }

        Write-Host "Waiting for API $ServiceName to become active ($i/$Attempts)..." -ForegroundColor Yellow
        Start-Sleep -Seconds $DelaySeconds
    }

    throw "API $ServiceName did not become active in project $ProjectId."
}

Write-Host "Using operational project $ProjectId" -ForegroundColor Cyan
gcloud config set project $ProjectId

Write-Host "Enabling required APIs..." -ForegroundColor Cyan
$requiredServices = @(
    "run.googleapis.com",
    "cloudbuild.googleapis.com",
    "artifactregistry.googleapis.com",
    "storage.googleapis.com",
    "sqladmin.googleapis.com",
    "cloudtasks.googleapis.com",
    "iam.googleapis.com",
    "aiplatform.googleapis.com"
)

gcloud services enable `
    run.googleapis.com `
    cloudbuild.googleapis.com `
    artifactregistry.googleapis.com `
    storage.googleapis.com `
    sqladmin.googleapis.com `
    cloudtasks.googleapis.com `
    iam.googleapis.com `
    aiplatform.googleapis.com `
    --project=$ProjectId

foreach ($service in $requiredServices) {
    Wait-ForEnabledService -ProjectId $ProjectId -ServiceName $service
}

Write-Host "Ensuring Artifact Registry repository $Repository..." -ForegroundColor Cyan
$existingRepo = gcloud artifacts repositories describe $Repository --project=$ProjectId --location=$Region --format="value(name)" 2>$null
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($existingRepo)) {
    gcloud artifacts repositories create $Repository `
        --project=$ProjectId `
        --repository-format=docker `
        --location=$Region `
        --description="Artwork Mockups Docker images"
} else {
    Write-Host "Artifact Registry repository already exists: $Repository" -ForegroundColor Yellow
}

Write-Host "Ensuring GCS bucket $Bucket..." -ForegroundColor Cyan
$existingBucket = gcloud storage buckets describe "gs://$Bucket" --project=$ProjectId --format="value(name)" 2>$null
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($existingBucket)) {
    gcloud storage buckets create "gs://$Bucket" `
        --project=$ProjectId `
        --location=$Region `
        --uniform-bucket-level-access
} else {
    Write-Host "Bucket already exists: $Bucket" -ForegroundColor Yellow
}

$webSa = Ensure-ServiceAccount -ProjectId $ProjectId -AccountName $WebServiceAccountName -DisplayName "Mockups Web Cloud Run"
$workerSa = Ensure-ServiceAccount -ProjectId $ProjectId -AccountName $WorkerServiceAccountName -DisplayName "Mockups Worker Cloud Run"
$tasksInvokerSa = Ensure-ServiceAccount -ProjectId $ProjectId -AccountName $TasksInvokerServiceAccountName -DisplayName "Mockups Cloud Tasks Invoker"

Ensure-ProjectRole -ProjectId $ProjectId -Member "serviceAccount:$webSa" -Role "roles/cloudtasks.enqueuer"
Ensure-ProjectRole -ProjectId $ProjectId -Member "serviceAccount:$webSa" -Role "roles/cloudtasks.taskDeleter"
Ensure-ProjectRole -ProjectId $ProjectId -Member "serviceAccount:$webSa" -Role "roles/storage.objectAdmin"
Ensure-ProjectRole -ProjectId $ProjectId -Member "serviceAccount:$webSa" -Role "roles/cloudsql.client"
Ensure-ProjectRole -ProjectId $ProjectId -Member "serviceAccount:$workerSa" -Role "roles/storage.objectAdmin"
Ensure-ProjectRole -ProjectId $ProjectId -Member "serviceAccount:$workerSa" -Role "roles/cloudsql.client"
Ensure-ProjectRole -ProjectId $ProjectId -Member "serviceAccount:$webSa" -Role "roles/aiplatform.user"
Ensure-ProjectRole -ProjectId $ProjectId -Member "serviceAccount:$workerSa" -Role "roles/aiplatform.user"

# CloudTasksService creates OIDC-authenticated tasks using the dedicated
# invoker identity. The web service is the task creator, so it must be allowed
# to act as that identity when attaching it to the task.
Write-Host "Allowing $webSa to use the Cloud Tasks invoker identity..." -ForegroundColor Cyan
gcloud iam service-accounts add-iam-policy-binding $tasksInvokerSa `
    --project=$ProjectId `
    --member="serviceAccount:$webSa" `
    --role="roles/iam.serviceAccountUser" `
    --condition=None | Out-Null

$projectNumber = gcloud projects describe $ProjectId --format="value(projectNumber)"
$cloudBuildSa = "$projectNumber@cloudbuild.gserviceaccount.com"
Ensure-ProjectRole -ProjectId $ProjectId -Member "serviceAccount:$cloudBuildSa" -Role "roles/run.admin"
Ensure-ProjectRole -ProjectId $ProjectId -Member "serviceAccount:$cloudBuildSa" -Role "roles/iam.serviceAccountUser"
Ensure-ProjectRole -ProjectId $ProjectId -Member "serviceAccount:$cloudBuildSa" -Role "roles/artifactregistry.writer"

Write-Host "Ensuring Cloud Tasks queue $QueueName..." -ForegroundColor Cyan
$existingQueue = gcloud tasks queues describe $QueueName --project=$ProjectId --location=$Region --format="value(name)" 2>$null
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($existingQueue)) {
    gcloud tasks queues create $QueueName `
        --project=$ProjectId `
        --location=$Region
} else {
    Write-Host "Cloud Tasks queue already exists: $QueueName" -ForegroundColor Yellow
}

Write-Host "SUCCESS: Operational project bootstrap completed." -ForegroundColor Green
Write-Host "Web service account: $webSa" -ForegroundColor Green
Write-Host "Worker service account: $workerSa" -ForegroundColor Green
Write-Host "Tasks invoker service account: $tasksInvokerSa" -ForegroundColor Green
