param(
    [string]$ProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$Region = "us-central1",
    [string]$Repository = "mockups-repo",
    [string]$WebService = "mockups-web",
    [string]$WorkerService = "mockups-worker",
    [string]$TasksInvokerServiceAccountName = "mockups-tasks-invoker-sa"
)

# deploy.ps1
# Local script to build and deploy both mockups-web and mockups-worker quickly.

$ErrorActionPreference = "Stop"

# 1. Update the local world mothers metadata index
if (Get-Command php -ErrorAction SilentlyContinue) {
    Write-Host "Updating local world mothers index..." -ForegroundColor Cyan
    php build_index.php
} else {
    Write-Host "PHP not found in global path. Skipping index rebuild (will use existing index.json)." -ForegroundColor Yellow
}

& "$PSScriptRoot\deploy_web.ps1" `
    -ProjectId $ProjectId `
    -Region $Region `
    -Repository $Repository `
    -WebService $WebService

& "$PSScriptRoot\deploy_worker.ps1" `
    -ProjectId $ProjectId `
    -Region $Region `
    -Repository $Repository `
    -WorkerService $WorkerService `
    -TasksInvokerServiceAccountName $TasksInvokerServiceAccountName

Write-Host "SUCCESS: Both services successfully deployed to Cloud Run!" -ForegroundColor Green
