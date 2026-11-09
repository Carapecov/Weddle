$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$routerPath = Join-Path $projectRoot 'src/api/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
Push-Location (Join-Path $projectRoot 'src/api/public')
try {
    & php -d extension=pdo_pgsql -S 127.0.0.1:8000 $routerPath
    $serverExitCode = $LASTEXITCODE
} finally { Pop-Location }
exit $serverExitCode
