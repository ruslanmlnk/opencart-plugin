$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$dist = Join-Path $root 'dist'
$zip = Join-Path $dist 'prom_sync.ocmod.zip'
$source = Join-Path $root 'oc4'

if (-not (Test-Path $dist)) {
  New-Item -ItemType Directory -Path $dist | Out-Null
}

if (Test-Path $zip) {
  Remove-Item $zip
}

if (-not (Test-Path $source)) {
  throw "Missing oc4 directory: $source"
}

Push-Location $source
try {
  # Zip root must contain install.json and admin/catalog/system for OC4
  Compress-Archive -Path '*' -DestinationPath $zip
} finally {
  Pop-Location
}
