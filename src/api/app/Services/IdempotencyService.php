<?php

namespace App\Services;

use App\Domain\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IdempotencyService
{
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }

    public function run(string $merchant, string $operation, string $resource, string $key, array $command, callable $perform): array
    {
        $hash = hash('sha256', json_encode($this->canonical([
            'merchant_id' => $merchant, 'operation' => $operation, 'resource' => $resource,
            'command' => $command,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($merchant, $operation, $resource, $key, $hash, $perform) {
            $scope = ['merchant_id' => $merchant, 'operation' => $operation, 'resource_scope' => $resource, 'key' => $key];
            $inserted = DB::table('idempotency_records')->insertOrIgnore([
                ...$scope, 'id' => (string) Str::uuid(), 'request_hash' => $hash,
                'http_status' => null, 'response_body' => null, 'response_headers' => null, 'completed_at' => null,
            ]);
            $record = DB::table('idempotency_records')->where($scope)->lockForUpdate()->first();
            if (! $inserted) {
                if (! hash_equals($record->request_hash, $hash)) {
                    throw new ApiProblem(409, 'IDEMPOTENCY_CONFLICT', 'Chave já utilizada com comando diferente.');
                }
                if ($record->http_status === null) {
                    throw new ApiProblem(503, 'TEMPORARY_UNAVAILABLE', 'Operação em andamento. Repita com a mesma chave.');
                }

                return ['status' => (int) $record->http_status,
                    'body' => json_decode($record->response_body, true, 64, JSON_THROW_ON_ERROR),
                    'headers' => json_decode($record->response_headers, true, 16, JSON_THROW_ON_ERROR),
                    'replayed' => true];
            }

            try {
                // Savepoint ensures a cached business error never commits partial business writes.
                $result = DB::transaction($perform);
            } catch (ApiProblem $e) {
                if ($e->status >= 500) {
                    throw $e;
                }
                $result = ['status' => $e->status, 'body' => $e->body(), 'headers' => []];
            }
            DB::table('idempotency_records')->where('id', $record->id)->update([
                'http_status' => $result['status'], 'response_body' => json_encode($result['body'], JSON_THROW_ON_ERROR),
                'response_headers' => json_encode((object) ($result['headers'] ?? []), JSON_THROW_ON_ERROR),
                'completed_at' => now('UTC'),
            ]);

            return [...$result, 'replayed' => false];
        });
    }
}
