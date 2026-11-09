$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
Push-Location $projectRoot
try {
    & php scripts/configure.php
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    docker compose up -d --wait postgres
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    $composerCommand = (Get-Command composer).Source
    $composerArchive = Join-Path (Split-Path $composerCommand -Parent) 'composer.phar'
    if (Test-Path -LiteralPath $composerArchive) {
        Push-Location 'src/api'
        try { & php -d extension=pdo_pgsql $composerArchive install --no-interaction } finally { Pop-Location }
    } else { throw 'composer.phar was not found beside the Composer command. Install Composer for PHP first.' }
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & php -d extension=pdo_pgsql scripts/database.php
    exit $LASTEXITCODE
} finally { Pop-Location }
