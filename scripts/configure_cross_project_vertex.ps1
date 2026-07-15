param(
    [string]$InfraProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [Parameter(Mandatory = $true)]
    [string]$VertexProjectId,
    [string]$WebServiceAccount = "",
    [string]$WorkerServiceAccount = ""
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($WebServiceAccount)) {
    $WebServiceAccount = "mockups-web-sa@$InfraProjectId.iam.gserviceaccount.com"
}
if ([string]::IsNullOrWhiteSpace($WorkerServiceAccount)) {
    $WorkerServiceAccount = "mockups-worker-sa@$InfraProjectId.iam.gserviceaccount.com"
}

Write-Host "Enabling Vertex AI API on AI project $VertexProjectId..." -ForegroundColor Cyan
gcloud services enable aiplatform.googleapis.com --project=$VertexProjectId

$members = @(
    "serviceAccount:$WebServiceAccount",
    "serviceAccount:$WorkerServiceAccount"
)

foreach ($member in $members) {
    Write-Host "Granting roles/aiplatform.user to $member on $VertexProjectId..." -ForegroundColor Cyan
    gcloud projects add-iam-policy-binding $VertexProjectId `
        --member=$member `
        --role="roles/aiplatform.user" `
        --condition=None
}

Write-Host "SUCCESS: $InfraProjectId Cloud Run service accounts can call Vertex AI in $VertexProjectId." -ForegroundColor Green
