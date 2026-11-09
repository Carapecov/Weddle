<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$issueCredential = function (string $role, ?string $merchant): array {
    $token = bin2hex(random_bytes(32));
    $id = (string) Str::uuid();
    DB::table('demo_credentials')->insert([
        'id' => $id, 'merchant_id' => $merchant, 'role' => $role,
        'token_hash' => hash('sha256', $token), 'revoked_at' => null, 'created_at' => now('UTC'),
    ]);

    return ['id' => $id, 'credential_id' => $id, 'role' => $role, 'merchant_id' => $merchant, 'token' => $token, 'simulation' => true];
};

Artisan::command('demo:operator {--json : Emitir JSON em vez do token simples}', function () use ($issueCredential) {
    $issued = $issueCredential('operator', null);
    $this->line($this->option('json') ? json_encode($issued, JSON_THROW_ON_ERROR) : $issued['token']);
})->purpose('Emitir credencial local de operador de demonstração; guardar o token com segurança.');

Artisan::command('demo:merchant-key {merchant : UUID do vendedor existente} {--json}', function () use ($issueCredential) {
    $merchant = strtolower((string) $this->argument('merchant'));
    if (! Str::isUuid($merchant) || ! DB::table('merchants')->where('id', $merchant)->exists()) {
        $this->error('Vendedor fictício não encontrado.');

        return 1;
    }
    $issued = $issueCredential('merchant', $merchant);
    $this->line($this->option('json') ? json_encode($issued, JSON_THROW_ON_ERROR) : $issued['token']);

    return 0;
})->purpose('Emitir credencial local vinculada a um vendedor fictício.');

Artisan::command('demo:revoke-key {credential : UUID da credencial de demonstração} {--json}', function () {
    $id = strtolower((string) $this->argument('credential'));
    if (! Str::isUuid($id) || ! DB::table('demo_credentials')->where('id', $id)->exists()) {
        $this->error('Credencial de demonstração não encontrada.');

        return 1;
    }
    DB::table('demo_credentials')->where('id', $id)->whereNull('revoked_at')->update(['revoked_at' => now('UTC')]);
    $result = ['id' => $id, 'revoked' => true, 'simulation' => true];
    $this->line($this->option('json') ? json_encode($result, JSON_THROW_ON_ERROR) : 'Credencial de demonstração revogada.');

    return 0;
})->purpose('Revogar uma credencial local sem revelar o token; operação idempotente.');
