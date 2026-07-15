param(
    [string]$ProjectId = "project-ff549db7-4f7f-4b0c-9a5",
    [string]$Region = "us-central1",
    [string]$WebService = "mockups-web",
    [string]$Domain = "artworkmockups.com"
)

$ErrorActionPreference = "Continue"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "Configurando mapeo de dominio en Google Cloud Run" -ForegroundColor Cyan
Write-Host "Proyecto: $ProjectId" -ForegroundColor Cyan
Write-Host "Servicio: $WebService" -ForegroundColor Cyan
Write-Host "Región:   $Region" -ForegroundColor Cyan
Write-Host "Dominio:  $Domain y www.$Domain" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# 1. Configurar el proyecto activo
Write-Host "Estableciendo proyecto activo en gcloud..." -ForegroundColor Yellow
gcloud config set project $ProjectId

# 2. Crear mapeo del dominio raíz
Write-Host "`nCreando mapeo para el dominio raíz: $Domain..." -ForegroundColor Yellow
gcloud beta run domain-mappings create --service=$WebService --domain=$Domain --project=$ProjectId --region=$Region

# 3. Crear mapeo del subdominio www
Write-Host "`nCreando mapeo para el subdominio: www.$Domain..." -ForegroundColor Yellow
gcloud beta run domain-mappings create --service=$WebService --domain="www.$Domain" --project=$ProjectId --region=$Region

Write-Host "`n==========================================================" -ForegroundColor Green
Write-Host "Mapeo solicitado con éxito en Google Cloud Run." -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Green

# 4. Mostrar los registros DNS generados por Google Cloud
Write-Host "`nObteniendo registros DNS para configurar en IONOS..." -ForegroundColor Cyan
gcloud beta run domain-mappings describe --domain=$Domain --project=$ProjectId --region=$Region

Write-Host "`n----------------------------------------------------------" -ForegroundColor Yellow
Write-Host "INSTRUCCIONES PARA IONOS:" -ForegroundColor Yellow
Write-Host "1. Inicia sesión en tu cuenta de IONOS." -ForegroundColor White
Write-Host "2. Ve a Dominios & SSL -> elije $Domain -> DNS." -ForegroundColor White
Write-Host "3. Agrega los 4 registros de tipo 'A' (con Host '@') usando las direcciones IP que aparecieron arriba." -ForegroundColor White
Write-Host "4. Agrega un registro CNAME (con Host 'www') apuntando a: ghs.googlehosted.com." -ForegroundColor White
Write-Host "5. Guarda los cambios. Google tardará de 15 a 30 minutos en emitir el certificado SSL gratuito." -ForegroundColor White
Write-Host "----------------------------------------------------------" -ForegroundColor Yellow
