param([int]$Top = 30)

$ErrorActionPreference = "Stop"
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$tracked = @(& git -c core.quotepath=false -C $repoRoot ls-files)
if ($LASTEXITCODE -ne 0) {
    throw "Could not read tracked files."
}

$rows = foreach ($relativePath in $tracked) {
    $absolutePath = Join-Path $repoRoot $relativePath
    if (Test-Path -LiteralPath $absolutePath -PathType Leaf) {
        [pscustomobject]@{
            Bytes = (Get-Item -LiteralPath $absolutePath).Length
            Path = $relativePath
        }
    }
}

$total = ($rows | Measure-Object Bytes -Sum).Sum
$platformAssets = ($rows | Where-Object Path -Like 'platform/assets/*' | Measure-Object Bytes -Sum).Sum
Write-Host ("Tracked context: {0:N2} MB across {1} files" -f ($total / 1MB), $rows.Count)
Write-Host ("Tracked platform assets: {0:N2} MB" -f ($platformAssets / 1MB))
Write-Host "Largest tracked files:"
$rows | Sort-Object Bytes -Descending | Select-Object -First $Top `
    @{Name='MB';Expression={[math]::Round($_.Bytes / 1MB, 2)}}, Path | Format-Table -AutoSize

Write-Host "This report is read-only. A missing literal reference is not proof that an asset is unused."
