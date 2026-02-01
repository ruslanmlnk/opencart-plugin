$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$dist = Join-Path $root 'dist'
$zip = Join-Path $dist 'prom_sync_oc3.ocmod.zip'
$source = Join-Path $root 'oc3'

if (-not (Test-Path $dist)) {
  New-Item -ItemType Directory -Path $dist | Out-Null
}

if (Test-Path $zip) {
  Remove-Item $zip
}

if (-not (Test-Path $source)) {
  throw "Missing oc3 directory: $source"
}

Push-Location $source
try {
  # Zip root must contain install.xml and upload/ for OC3
  Compress-Archive -Path 'install.xml', 'upload' -DestinationPath $zip
} finally {
  Pop-Location
}
