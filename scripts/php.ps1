param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments)
$ErrorActionPreference = 'Stop'
Push-Location (Join-Path $PSScriptRoot '../src/api')
try {
    & php -d extension=pdo_pgsql @Arguments
    $commandExitCode = $LASTEXITCODE
} finally { Pop-Location }
exit $commandExitCode
