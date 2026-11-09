param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments)
$ErrorActionPreference = 'Stop'
& (Join-Path $PSScriptRoot 'php.ps1') vendor/bin/phpunit @Arguments
exit $LASTEXITCODE
