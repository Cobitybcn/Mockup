# Uso: despues de terminar cambios en localhost, correr este script.
# Hace: commit (si hace falta) -> push a main -> espera el build de Cloud Build
# (que incluye migracion de base de datos) -> confirma que termino OK.
# Si el build falla, el script para con error. NO reporta exito salvo que
# Cloud Build haya terminado en SUCCESS.

param(
    [string]$ProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$CommitMessage = "Deploy: cambios locales"
)

$ErrorActionPreference = "Stop"
$Gcloud = "gcloud.cmd"
$Git = "git"

function Fail($msg) {
    Write-Host "FALLO: $msg" -ForegroundColor Red
    exit 1
}

$branch = (& $Git rev-parse --abbrev-ref HEAD).Trim()
if ($branch -ne "main") {
    Fail "Estas en la rama '$branch', no en 'main'. Cambia a main antes de correr esto (o mergea a main)."
}

$status = & $Git status --porcelain
if ($status) {
    Write-Host "Hay cambios sin commitear. Commiteando..." -ForegroundColor Yellow
    & $Git add -A
    & $Git commit -m $CommitMessage
    if ($LASTEXITCODE -ne 0) { Fail "git commit fallo." }
}

Write-Host "Subiendo a origin/main..." -ForegroundColor Cyan
& $Git push origin main
if ($LASTEXITCODE -ne 0) { Fail "git push fallo." }

$commitSha = (& $Git rev-parse HEAD).Trim()
Write-Host "Commit desplegado: $commitSha" -ForegroundColor Cyan

Write-Host "Esperando que Cloud Build detecte el push..." -ForegroundColor Cyan
$buildId = $null
for ($i = 0; $i -lt 24; $i++) {
    Start-Sleep -Seconds 5
    $buildId = (& $Gcloud builds list `
        --project=$ProjectId `
        "--filter=substitutions.COMMIT_SHA=$commitSha" `
        "--format=value(id)" `
        --limit=1) | Select-Object -First 1
    if ($buildId) { break }
}
if (-not $buildId) {
    Fail "No aparecio ningun build de Cloud Build para el commit $commitSha en 2 minutos. Revisa el trigger en la consola de Cloud Build."
}
Write-Host "Build encontrado: $buildId. Siguiendo el progreso..." -ForegroundColor Cyan

$finalStatus = $null
for ($i = 0; $i -lt 180; $i++) {
    Start-Sleep -Seconds 10
    $finalStatus = (& $Gcloud builds describe $buildId --project=$ProjectId "--format=value(status)").Trim()
    Write-Host "  estado: $finalStatus"
    if ($finalStatus -in @("SUCCESS", "FAILURE", "TIMEOUT", "CANCELLED", "EXPIRED")) { break }
}

if ($finalStatus -ne "SUCCESS") {
    Write-Host "El build no termino OK (estado: $finalStatus)." -ForegroundColor Red
    Write-Host "Log: https://console.cloud.google.com/cloud-build/builds/$buildId?project=$ProjectId" -ForegroundColor Red
    Fail "Deploy incompleto. NO se considera entregado a produccion."
}

Write-Host ""
Write-Host "OK: build $buildId termino en SUCCESS." -ForegroundColor Green
Write-Host "Esto incluyo: build de imagenes, tests de regresion, migracion de base de datos (si habia migraciones nuevas), y despliegue con verificacion de trafico." -ForegroundColor Green
Write-Host "Log completo: https://console.cloud.google.com/cloud-build/builds/$buildId?project=$ProjectId" -ForegroundColor Green
