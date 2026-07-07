# deploy.ps1
# Local script to build and deploy both mockups-web and mockups-worker quickly.

# 1. Update the local world mothers metadata index
if (Get-Command php -ErrorAction SilentlyContinue) {
    Write-Host "Updating local world mothers index..." -ForegroundColor Cyan
    php build_index.php
} else {
    Write-Host "PHP not found in global path. Skipping index rebuild (will use existing index.json)." -ForegroundColor Yellow
}

# 2. Deploy Web container to Cloud Run
Write-Host "Deploying mockups-web..." -ForegroundColor Cyan
Copy-Item Dockerfile.web Dockerfile
gcloud builds submit --tag=us-central1-docker.pkg.dev/artwork-mockups-serverless/mockups-repo/mockups-web:latest .
Remove-Item Dockerfile
gcloud run deploy mockups-web `
    --image=us-central1-docker.pkg.dev/artwork-mockups-serverless/mockups-repo/mockups-web:latest `
    --allow-unauthenticated `
    --service-account=mockups-web-sa@artwork-mockups-serverless.iam.gserviceaccount.com `
    --min-instances=0 `
    --max-instances=2 `
    --region=us-central1

# 3. Deploy Worker container to Cloud Run
Write-Host "Deploying mockups-worker..." -ForegroundColor Cyan
Copy-Item Dockerfile.worker Dockerfile
gcloud builds submit --tag=us-central1-docker.pkg.dev/artwork-mockups-serverless/mockups-repo/mockups-worker:latest .
Remove-Item Dockerfile
gcloud run deploy mockups-worker `
    --image=us-central1-docker.pkg.dev/artwork-mockups-serverless/mockups-repo/mockups-worker:latest `
    --no-allow-unauthenticated `
    --service-account=mockups-worker-sa@artwork-mockups-serverless.iam.gserviceaccount.com `
    --min-instances=0 `
    --max-instances=2 `
    --region=us-central1

Write-Host "SUCCESS: Both services successfully deployed to Cloud Run!" -ForegroundColor Green
