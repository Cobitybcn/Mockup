param([string]$Output = (Join-Path $PSScriptRoot '..\deploy\admin-v2'))
$ErrorActionPreference='Stop';$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path;$outputFull=[System.IO.Path]::GetFullPath($Output)
if(-not $outputFull.StartsWith($root,[System.StringComparison]::OrdinalIgnoreCase)){throw 'Deploy output must remain inside the website project.'}
if(Test-Path -LiteralPath $outputFull){Remove-Item -LiteralPath $outputFull -Recurse -Force};New-Item -ItemType Directory -Path $outputFull -Force|Out-Null
$files=@('admin-v2/index.php','admin-v2/admin-v2.css','api/v2/artworks/sync.php','inc/LocalEnv.php','inc/ArtworkSyncV2Authenticator.php','inc/ArtistCatalogV2Repository.php','data/catalog-v2/editorial/.gitkeep','data/catalog-v2/commerce/.gitkeep','data/catalog-v2/sync-state/.gitkeep')
$manifest=@();foreach($relative in $files){$source=Join-Path $root $relative;if(-not(Test-Path -LiteralPath $source)){throw "Missing deploy source: $relative"};$target=Join-Path $outputFull $relative;$dir=Split-Path $target -Parent;New-Item -ItemType Directory -Path $dir -Force|Out-Null;Copy-Item -LiteralPath $source -Destination $target -Force;$manifest+=[ordered]@{local_path=$relative;remote_path=('/public/maurizio-website-new/'+$relative.Replace('\','/'));bytes=(Get-Item $source).Length;sha256=(Get-FileHash $source -Algorithm SHA256).Hash.ToLower()}}
$manifest|ConvertTo-Json -Depth 4|Set-Content -LiteralPath (Join-Path $outputFull 'deploy-manifest.json') -Encoding utf8
Write-Output "DEPLOY_PACKAGE=$outputFull";Write-Output "FILES=$($files.Count)"
