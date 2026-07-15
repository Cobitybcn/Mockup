param(
    [string]$ProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$Region = "us-central1",
    [string]$VertexProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$VertexLocation = "global",
    [string]$WebService = "mockups-web",
    [string]$WorkerService = "mockups-worker",
    [string]$Bucket = "project-ff549db7-4f7f-4b0c-9a5-artwork-mockups-storage",
    [string]$QueueName = "mockups-generation-queue",
    [string]$WorkerUrl = "",
    [string]$TasksInvokerServiceAccount = "",
    [Parameter(Mandatory = $true)]
    [string]$CloudSqlInstance,
    [Parameter(Mandatory = $true)]
    [string]$DbDatabase,
    [Parameter(Mandatory = $true)]
    [string]$DbUsername,
    [Parameter(Mandatory = $true)]
    [string]$DbPassword,
    [string]$GeminiImageModel = "gemini-3.1-flash-image"
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($VertexProjectId)) {
    $VertexProjectId = $ProjectId
}
if ([string]::IsNullOrWhiteSpace($WorkerUrl)) {
    $workerBaseUrl = gcloud run services describe $WorkerService --project=$ProjectId --region=$Region --format="value(status.url)" 2>$null
    if ([string]::IsNullOrWhiteSpace($workerBaseUrl)) {
        throw "WorkerUrl was not provided and Cloud Run service '$WorkerService' was not found in project '$ProjectId'. Deploy the worker first or pass -WorkerUrl explicitly."
    }
    $WorkerUrl = $workerBaseUrl.TrimEnd("/") + "/poc_worker.php"
}
if ([string]::IsNullOrWhiteSpace($TasksInvokerServiceAccount)) {
    $TasksInvokerServiceAccount = "mockups-tasks-invoker-sa@$ProjectId.iam.gserviceaccount.com"
}

function Join-EnvVars {
    param([hashtable]$Values)

    $pairs = ($Values.GetEnumerator() | Sort-Object Name | ForEach-Object {
        "$($_.Name)=$($_.Value)"
    }) -join "|"

    return "^|^$pairs"
}

$sharedEnv = @{
    APP_MODE             = "gemini"
    ALLOW_REAL_API       = "true"
    IMAGE_PROVIDER       = "gemini"
    GCP_PROJECT_ID       = $ProjectId
    GCP_LOCATION         = $Region
    GCP_QUEUE_NAME       = $QueueName
    GCP_WORKER_URL       = $WorkerUrl
    GCP_TASKS_INVOKER_SA = $TasksInvokerServiceAccount
    GCS_BUCKET_NAME      = $Bucket
    VERTEX_PROJECT_ID    = $VertexProjectId
    VERTEX_LOCATION      = $VertexLocation
    GEMINI_IMAGE_MODEL   = $GeminiImageModel
    DB_CONNECTION        = "mysql"
    DB_SOCKET            = "/cloudsql/$CloudSqlInstance"
    DB_DATABASE          = $DbDatabase
    DB_USERNAME          = $DbUsername
    DB_PASSWORD          = $DbPassword
    DB_CHARSET           = "utf8mb4"
}

$envVars = Join-EnvVars $sharedEnv

Write-Host "Updating Cloud Run runtime environment for $WebService..." -ForegroundColor Cyan
gcloud run services update $WebService `
    --project=$ProjectId `
    --region=$Region `
    --add-cloudsql-instances=$CloudSqlInstance `
    --update-env-vars=$envVars

Write-Host "Updating Cloud Run runtime environment for $WorkerService..." -ForegroundColor Cyan
gcloud run services update $WorkerService `
    --project=$ProjectId `
    --region=$Region `
    --add-cloudsql-instances=$CloudSqlInstance `
    --update-env-vars=$envVars

Write-Host "SUCCESS: Cloud Run runtime environment updated for web and worker." -ForegroundColor Green
