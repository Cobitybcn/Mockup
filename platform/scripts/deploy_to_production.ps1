# Acompana una release por el flujo del repo. NUNCA commitea ni decide por vos.
#
#   rama codex/*  -> sube la rama y espera el preflight (construye y corre los
#                    tests dentro de la imagen real; NO despliega nada).
#   rama main     -> sube main y espera el despliegue, y despues verifica que la
#                    revision nueva lleve tu commit y tenga el 100% del trafico.
#
# Si hay cambios sin commitear, para y no toca nada: commitealos vos, eligiendo
# que entra en la release. La version anterior de este script hacia 'git add -A',
# que barria trabajo no relacionado hacia una release de produccion.
#
# No reporta exito salvo que Cloud Build haya terminado en SUCCESS.

param(
    [string]$ProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$Region = "us-central1",
    [string]$WebService = "mockups-web"
)

$ErrorActionPreference = "Stop"
$Gcloud = "gcloud.cmd"
$Git = "git"

function Fail($msg) {
    Write-Host "FALLO: $msg" -ForegroundColor Red
    exit 1
}

# --- 1. El arbol tiene que estar limpio: la seleccion de la release es tuya ---
$status = & $Git status --porcelain
if ($status) {
    Write-Host "Hay cambios sin commitear:" -ForegroundColor Yellow
    Write-Host $status
    Write-Host ""
    Fail "Commitea a mano lo que quieras publicar (git add <archivos> ; git commit). Este script no elige por vos."
}

# --- 2. La rama define que se hace ---
$branch = (& $Git rev-parse --abbrev-ref HEAD).Trim()
if ($branch -eq "main") {
    $mode = "deploy"
    Write-Host "Rama main: esto DESPLIEGA a produccion." -ForegroundColor Yellow
} elseif ($branch -like "codex/*") {
    $mode = "preflight"
    Write-Host "Rama $branch : esto corre el preflight y NO despliega nada." -ForegroundColor Cyan
} else {
    Fail "Estas en '$branch'. Trabaja en una rama 'codex/*' y mergea a main con squash cuando el preflight este verde."
}

Write-Host "Subiendo $branch..." -ForegroundColor Cyan
& $Git push origin $branch
if ($LASTEXITCODE -ne 0) { Fail "git push fallo." }

$commitSha = (& $Git rev-parse HEAD).Trim()
Write-Host "Commit: $commitSha" -ForegroundColor Cyan

# --- 3. Encontrar el build que disparo el push ---
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
    Write-Host "No aparecio ningun build para $commitSha en 2 minutos." -ForegroundColor Yellow
    Write-Host "Puede ser normal: los triggers ignoran los .md y platform/docs/**, asi que" -ForegroundColor Yellow
    Write-Host "una release de solo documentacion no construye nada." -ForegroundColor Yellow
    exit 0
}
Write-Host "Build: $buildId" -ForegroundColor Cyan

$finalStatus = $null
for ($i = 0; $i -lt 180; $i++) {
    Start-Sleep -Seconds 10
    $finalStatus = (& $Gcloud builds describe $buildId --project=$ProjectId "--format=value(status)").Trim()
    Write-Host "  estado: $finalStatus"
    if ($finalStatus -in @("SUCCESS", "FAILURE", "TIMEOUT", "CANCELLED", "EXPIRED")) { break }
}

$logUrl = "https://console.cloud.google.com/cloud-build/builds/$buildId" + "?project=$ProjectId"
if ($finalStatus -ne "SUCCESS") {
    Write-Host "El build no termino OK (estado: $finalStatus)." -ForegroundColor Red
    Write-Host "Log: $logUrl" -ForegroundColor Red
    Fail "NO se considera entregado."
}

# --- 4. Preflight: no hay nada desplegado que verificar ---
if ($mode -eq "preflight") {
    Write-Host ""
    Write-Host "OK: preflight verde (build + tests de regresion dentro de la imagen)." -ForegroundColor Green
    Write-Host "No se desplego nada. Para publicar: mergea $branch a main con squash." -ForegroundColor Green
    Write-Host "Log: $logUrl" -ForegroundColor Green
    exit 0
}

# --- 5. Deploy: el build en verde no alcanza, hay que ver la revision viva ---
Write-Host ""
Write-Host "Verificando la revision en Cloud Run..." -ForegroundColor Cyan
$revision = (& $Gcloud run services describe $WebService --project=$ProjectId --region=$Region `
    "--format=value(status.traffic[0].revisionName)").Trim()
$percent = (& $Gcloud run services describe $WebService --project=$ProjectId --region=$Region `
    "--format=value(status.traffic[0].percent)").Trim()

if ($revision -notlike "*$($commitSha.Substring(0,7))*") {
    Write-Host "La revision con trafico es '$revision', que no corresponde al commit $commitSha." -ForegroundColor Red
    Write-Host "Ojo: el trafico esta fijado a revisiones con nombre, asi que una revision nueva" -ForegroundColor Red
    Write-Host "puede existir sin recibir trafico. Revisa en la consola de Cloud Run." -ForegroundColor Red
    Fail "El build salio bien pero tu commit no es el que esta atendiendo."
}
if ($percent -ne "100") {
    Fail "La revision $revision tiene $percent% del trafico, no 100%."
}

Write-Host ""
Write-Host "OK: build $buildId en SUCCESS." -ForegroundColor Green
Write-Host "Revision publicada: $revision (100% del trafico)." -ForegroundColor Green
Write-Host "Incluyo: imagenes, tests de regresion, migraciones si habia, y despliegue verificado." -ForegroundColor Green
Write-Host "Log: $logUrl" -ForegroundColor Green
