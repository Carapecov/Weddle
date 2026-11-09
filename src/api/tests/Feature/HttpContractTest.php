<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PostgresTestCase;

final class HttpContractTest extends PostgresTestCase
{
    public function test_only_operator_can_create_merchants_and_no_http_response_issues_tokens(): void
    {
        $operator = ['token' => $this->credential()];
        $payload = ['name' => 'DEMO-ARCTIC', 'virtual_account_id' => 'DEMO-ACCOUNT-ARCTIC'];
        $response = $this->api($operator, 'POST', '/api/v1/merchants', $payload)->assertCreated();
        $this->assertContract($response->json(), 'Merchant');
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertError($this->api($operator, 'POST', '/api/v1/merchants', $payload), 409);
        $actor = $this->merchant();
        $this->assertError($this->api($actor, 'POST', '/api/v1/merchants', [
            'name' => 'DEMO-OTHER', 'virtual_account_id' => 'DEMO-ACCOUNT-OTHER',
        ]), 403);
    }

    public function test_missing_invalid_and_revoked_bearer_credentials_are_rejected(): void
    {
        $this->assertError($this->api(null, 'POST', '/api/v1/merchants', []), 401);
        $this->assertError($this->api(['token' => 'demo-invalid-not-provisioned'], 'POST', '/api/v1/merchants', []), 401);
        $token = $this->credential();
        DB::table('demo_credentials')->where('token_hash', hash('sha256', $token))->update(['revoked_at' => now()]);
        $response = $this->api(['token' => $token], 'POST', '/api/v1/merchants', []);
        $this->assertError($response, 401);
        $this->assertStringNotContainsString($token, $response->getContent());
    }

    public function test_t12_tenant_isolation_applies_to_queries_mutations_and_references(): void
    {
        $a = $this->merchant();
        $b = $this->merchant();
        $document = $this->document($b);
        $payment = $this->payment($b, $document)['payment'];
        foreach (['', '/ledger', '/events'] as $suffix) {
            $this->assertError($this->api($a, 'GET', '/api/v1/payments/'.$payment['id'].$suffix), 404);
        }
        foreach (['authorize', 'settle', 'refund'] as $command) {
            $this->assertError($this->command($a, $payment['id'], $command), 404);
        }
        $this->assertError($this->api($a, 'POST', '/api/v1/payments', [
            'merchant_id' => $a['id'], 'fiscal_document_id' => $document['id'],
            'amount_cents' => 100000, 'currency' => 'BRL', 'scenario' => 'approved',
        ], (string) Str::uuid()), 404);
        $this->assertSame('CREATED', DB::table('payments')->where('id', $payment['id'])->value('status'));
        $this->assertSame(0, DB::table('ledger_entries')->count());
    }

    public function test_operator_cannot_bypass_merchant_routes(): void
    {
        $operator = ['token' => $this->credential()];
        $actor = $this->merchant();
        $document = $this->document($actor);
        $payment = $this->payment($actor, $document)['payment'];
        $this->assertError($this->api($operator, 'GET', '/api/v1/payments/'.$payment['id']), 403);
        $this->assertError($this->api($operator, 'POST', '/api/v1/fiscal-documents', [
            'merchant_id' => $actor['id'], 'reference' => 'DEMO-DOC-OPERATOR',
            'gross_amount_cents' => 100, 'cbs_amount_cents' => 0, 'ibs_amount_cents' => 0,
        ]), 403);
    }

    #[DataProvider('invalidDocumentAmounts')]
    public function test_t13_document_amounts_require_json_integers_inside_the_approved_bounds(string $field, mixed $value): void
    {
        $actor = $this->merchant();
        $payload = ['merchant_id' => $actor['id'], 'reference' => 'DEMO-DOC-TYPES',
            'gross_amount_cents' => 100000, 'cbs_amount_cents' => 8000, 'ibs_amount_cents' => 12000];
        $payload[$field] = $value;
        $this->assertError($this->api($actor, 'POST', '/api/v1/fiscal-documents', $payload), 422);
        $this->assertSame(0, DB::table('fiscal_documents')->count());
    }

    public static function invalidDocumentAmounts(): iterable
    {
        yield 'zero-gross' => ['gross_amount_cents', 0];
        yield 'negative-gross' => ['gross_amount_cents', -1];
        yield 'maximum-plus-one' => ['gross_amount_cents', 1000000000001];
        yield 'integer-overflow' => ['gross_amount_cents', PHP_INT_MAX];
        yield 'numeric-string' => ['gross_amount_cents', '100000'];
        yield 'decimal-number' => ['gross_amount_cents', 100000.0];
        yield 'boolean' => ['gross_amount_cents', true];
        yield 'null' => ['gross_amount_cents', null];
        yield 'negative-cbs' => ['cbs_amount_cents', -1];
        yield 'cbs-string' => ['cbs_amount_cents', '8000'];
        yield 'ibs-fraction' => ['ibs_amount_cents', 1.5];
        yield 'tax-overflow' => ['ibs_amount_cents', PHP_INT_MAX];
    }

    #[DataProvider('invalidPaymentFields')]
    public function test_payment_schema_rejects_invalid_types_values_and_unknown_fields(string $field, mixed $value): void
    {
        $actor = $this->merchant();
        $document = $this->document($actor);
        $payload = ['merchant_id' => $actor['id'], 'fiscal_document_id' => $document['id'],
            'amount_cents' => 100000, 'currency' => 'BRL', 'scenario' => 'approved'];
        $payload[$field] = $value;
        $this->assertError($this->api($actor, 'POST', '/api/v1/payments', $payload, (string) Str::uuid()), 422);
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('ledger_entries')->count());
    }

    public static function invalidPaymentFields(): iterable
    {
        yield 'string-amount' => ['amount_cents', '100000'];
        yield 'float-amount' => ['amount_cents', 100000.0];
        yield 'zero-amount' => ['amount_cents', 0];
        yield 'above-maximum' => ['amount_cents', 1000000000001];
        yield 'mismatched-gross' => ['amount_cents', 99999];
        yield 'non-brl' => ['currency', 'USD'];
        yield 'unknown-scenario' => ['scenario', 'random'];
        yield 'missing-scenario' => ['scenario', null];
        yield 'public-failure-injection' => ['failure_mode', 'terminal'];
        yield 'public-failpoint' => ['fail_at', 'settlement_after_ledger'];
        yield 'card-field-prohibited' => ['card', 'NOT-A-REAL-CARD'];
    }

    #[DataProvider('paymentOperations')]
    public function test_every_payment_mutation_requires_an_idempotency_key(string $operation): void
    {
        $actor = $this->merchant();
        $document = $this->document($actor);
        if ($operation === 'create') {
            $response = $this->api($actor, 'POST', '/api/v1/payments', [
                'merchant_id' => $actor['id'], 'fiscal_document_id' => $document['id'],
                'amount_cents' => 100000, 'currency' => 'BRL', 'scenario' => 'approved',
            ]);
        } else {
            $payment = $this->payment($actor, $document)['payment'];
            $response = $this->api($actor, 'POST', '/api/v1/payments/'.$payment['id'].'/'.$operation);
        }
        $this->assertError($response, 400);
        $this->assertSame(0, DB::table('ledger_entries')->count());
    }

    public static function paymentOperations(): iterable
    {
        yield ['create'];
        yield ['authorize'];
        yield ['settle'];
        yield ['refund'];
    }

    public function test_nonempty_command_bodies_and_invalid_keys_are_rejected(): void
    {
        $actor = $this->merchant();
        $payment = $this->payment($actor, $this->document($actor))['payment'];
        $this->assertError($this->api($actor, 'POST', '/api/v1/payments/'.$payment['id'].'/authorize',
            ['scenario' => 'declined'], (string) Str::uuid()), 422);
        foreach (['', 'not visible', str_repeat('x', 129)] as $key) {
            $this->assertError($this->api($actor, 'POST', '/api/v1/payments/'.$payment['id'].'/authorize', null, $key), 400);
        }
        $this->assertSame('CREATED', DB::table('payments')->where('id', $payment['id'])->value('status'));
    }

    public function test_demo_identifiers_missing_fields_and_duplicate_references_are_validated(): void
    {
        $operator = ['token' => $this->credential()];
        $this->assertError($this->api($operator, 'POST', '/api/v1/merchants', [
            'name' => 'REAL-PERSON-NOT-DEMO', 'virtual_account_id' => 'DEMO-ACCOUNT-ABC',
        ]), 422);
        $actor = $this->merchant();
        $this->assertError($this->api($actor, 'POST', '/api/v1/fiscal-documents', []), 422);
        $document = $this->document($actor);
        $payload = ['merchant_id' => $actor['id'], 'reference' => $document['reference'],
            'gross_amount_cents' => 100000, 'cbs_amount_cents' => 8000, 'ibs_amount_cents' => 12000];
        $this->assertError($this->api($actor, 'POST', '/api/v1/fiscal-documents', $payload), 409);
        $this->assertSame(1, DB::table('fiscal_documents')->count());
    }

    public function test_malformed_oversized_and_non_json_requests_have_sanitized_errors(): void
    {
        $actor = $this->merchant();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$actor['token']];
        $this->assertError($this->call('POST', '/api/v1/fiscal-documents', [], [], [], $server, '{"broken":'), 400);
        $this->assertError($this->call('POST', '/api/v1/fiscal-documents', [], [], [], $server,
            json_encode(['oversized' => str_repeat('a', 17000)], JSON_THROW_ON_ERROR)), 413);
        $server['CONTENT_TYPE'] = 'text/plain';
        $this->assertError($this->call('POST', '/api/v1/fiscal-documents', [], [], [], $server, 'demo text'), 415);
        $this->assertSame(0, DB::table('fiscal_documents')->count());
    }

    public function test_health_is_public_and_reports_database_availability_without_private_details(): void
    {
        $healthy = $this->api(null, 'GET', '/health')->assertOk()->assertJsonPath('database', 'ok');
        $this->assertContract($healthy->json(), 'Health');
        $original = config('database.connections.pgsql');
        try {
            config(['database.connections.pgsql.port' => 1, 'database.connections.pgsql.connect_timeout' => 1]);
            DB::purge('pgsql');
            $unavailable = $this->api(null, 'GET', '/health')->assertStatus(503)->assertJsonPath('database', 'unavailable');
            $this->assertContract($unavailable->json(), 'Health');
            $this->assertStringNotContainsString('SQLSTATE', $unavailable->getContent());
            $this->assertStringNotContainsString('password', strtolower($unavailable->getContent()));
        } finally {
            config(['database.connections.pgsql' => $original]);
            DB::purge('pgsql');
        }
    }

    public function test_rate_limit_returns_429_and_retry_after_without_changing_business_data(): void
    {
        $actor = $this->merchant();
        $payment = $this->payment($actor, $this->document($actor))['payment'];
        $limited = null;
        for ($attempt = 0; $attempt < 65; $attempt++) {
            $response = $this->api($actor, 'GET', '/api/v1/payments/'.$payment['id']);
            if ($response->status() === 429) {
                $limited = $response;
                break;
            }
            $response->assertOk();
        }
        $this->assertNotNull($limited, 'Expected configured per-credential rate limit');
        $this->assertError($limited, 429);
        $this->assertGreaterThanOrEqual(1, (int) $limited->headers->get('Retry-After'));
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(0, DB::table('ledger_entries')->count());
    }

    public function test_external_origin_preflight_does_not_receive_cors_permission(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/payments', [], [], [], [
            'HTTP_ORIGIN' => 'https://example.invalid',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'),
            'The API-only MVP must not grant cross-origin access to arbitrary external origins');
    }
}
