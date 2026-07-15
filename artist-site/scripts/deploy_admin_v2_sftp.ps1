param(
    [ValidateSet('scan-host-key', 'dry-run', 'deploy', 'sync-shared-secret')]
    [string]$Action = 'dry-run'
)
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$python = Join-Path $root 'tools\sftp-venv\Scripts\python.exe'
if (-not (Test-Path -LiteralPath $python)) { throw 'SFTP runtime is not installed.' }
& $python (Join-Path $root 'tools\sftp_deploy.py') ("--$Action")
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
