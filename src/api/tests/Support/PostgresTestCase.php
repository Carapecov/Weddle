<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class PostgresTestCase extends TestCase
{
    use ContractAssertions;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSafeDatabase();
        if (! self::$migrated) {
            $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
            self::$migrated = true;
        }

        // No surrounding test transaction: deferred constraints must execute and
        // committed fixtures must be visible to independent concurrency workers.
        $this->assertSafeDatabase();
        DB::unprepared('TRUNCATE TABLE idempotency_records, ledger_entries, document_settlements, payment_events, payments, fiscal_documents, demo_credentials, merchants CASCADE');
        config(['weddell.failure_mode' => 'none', 'weddell.fail_at' => null]);
    }

    protected function assertSafeDatabase(): void
    {
        $this->assertTrue($this->app->environment('testing'), 'Refusing database changes outside APP_ENV=testing');
        $this->assertSame('pgsql', config('database.default'), 'PostgreSQL is mandatory');
        $this->assertSame('127.0.0.1', config('database.connections.pgsql.host'), 'Only the dedicated local test service is allowed');
        $this->assertSame(55432, (int) config('database.connections.pgsql.port'), 'Only the dedicated test service port is allowed');
        $this->assertSame('weddell_pay_test', config('database.connections.pgsql.database'), 'Refusing database changes outside the dedicated test database');
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('weddell_pay_test', DB::selectOne('SELECT current_database() AS name')->name, 'Connected database must be the dedicated test database');
    }

    protected function merchant(): array
    {
        $suffix = strtoupper(Str::random(12));
        $id = (string) Str::uuid();
        DB::table('merchants')->insert([
            'id' => $id, 'name' => 'DEMO-'.$suffix,
            'virtual_account_id' => 'DEMO-ACCOUNT-'.$suffix, 'created_at' => now(),
        ]);

        return ['id' => $id, 'token' => $this->credential('merchant', $id)];
    }

    protected function credential(string $role = 'operator', ?string $merchant = null): string
    {
        $token = 'demo-test-'.Str::random(48);
        DB::table('demo_credentials')->insert([
            'id' => (string) Str::uuid(), 'merchant_id' => $merchant,
            'role' => $role, 'token_hash' => hash('sha256', $token),
            'revoked_at' => null, 'created_at' => now(),
        ]);

        return $token;
    }

    protected function api(?array $actor, string $method, string $uri, ?array $body = null, ?string $key = null): TestResponse
    {
        $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($actor !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$actor['token'];
        }
        if ($key !== null) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
        }

        return $this->call($method, $uri, [], [], [], $headers, $body === null ? '' : json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    protected function document(array $actor, int $gross = 100000, int $cbs = 8000, int $ibs = 12000): array
    {
        $response = $this->api($actor, 'POST', '/api/v1/fiscal-documents', [
            'merchant_id' => $actor['id'], 'reference' => 'DEMO-DOC-'.strtoupper(Str::random(12)),
            'gross_amount_cents' => $gross, 'cbs_amount_cents' => $cbs, 'ibs_amount_cents' => $ibs,
        ])->assertCreated();
        $this->assertContract($response->json(), 'FiscalDocument');

        return $response->json();
    }

    protected function payment(array $actor, array $document, string $scenario = 'approved', ?string $key = null): array
    {
        $key ??= (string) Str::uuid();
        $payload = ['merchant_id' => $actor['id'], 'fiscal_document_id' => $document['id'],
            'amount_cents' => $document['gross_amount_cents'], 'currency' => 'BRL', 'scenario' => $scenario];
        $response = $this->api($actor, 'POST', '/api/v1/payments', $payload, $key)->assertCreated();
        $this->assertContract($response->json(), 'Payment');

        return ['payment' => $response->json(), 'payload' => $payload, 'key' => $key, 'response' => $response];
    }

    protected function command(array $actor, string $payment, string $command, ?string $key = null): TestResponse
    {
        return $this->api($actor, 'POST', '/api/v1/payments/'.$payment.'/'.$command, null, $key ?? (string) Str::uuid());
    }

    protected function authorized(array $actor, ?array $document = null): array
    {
        $created = $this->payment($actor, $document ?? $this->document($actor));
        $this->command($actor, $created['payment']['id'], 'authorize')->assertOk()->assertJsonPath('status', 'AUTHORIZED');

        return $created['payment'];
    }

    protected function settled(array $actor, ?array $document = null): array
    {
        $payment = $this->authorized($actor, $document);
        $response = $this->command($actor, $payment['id'], 'settle')->assertOk()->assertJsonPath('status', 'SETTLED');

        return $response->json();
    }

    protected function assertError(TestResponse $response, int $status, ?string $code = null): void
    {
        $response->assertStatus($status);
        $this->assertContract($response->json(), 'Error');
        if ($code !== null) {
            $response->assertJsonPath('error.code', $code);
        }
        $correlation = $response->headers->get('X-Correlation-ID');
        $this->assertIsString($correlation);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $correlation);
        // Cached errors keep the original body, while the response header traces
        // the current request. Fresh errors must use the same correlation ID.
        if ($response->headers->get('Idempotency-Replayed') !== 'true') {
            $this->assertSame($response->json('error.correlation_id'), $correlation);
        }
        foreach (['SQLSTATE', 'Stack trace', 'PDOException', 'password=', 'token_hash', 'C:\\'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
    }

    protected function assertBalanced(string $payment): void
    {
        $groups = DB::table('ledger_entries')->where('payment_id', $payment)->get()->groupBy('event_id');
        $this->assertNotEmpty($groups->all(), 'Expected financial entries');
        foreach ($groups as $entries) {
            $debits = 0;
            $credits = 0;
            foreach ($entries as $entry) {
                $this->assertGreaterThan(0, (int) $entry->amount_cents);
                if ($entry->direction === 'DEBIT') {
                    $debits += (int) $entry->amount_cents;
                } else {
                    $this->assertSame('CREDIT', $entry->direction);
                    $credits += (int) $entry->amount_cents;
                }
            }
            $this->assertSame($debits, $credits, 'Unbalanced event');
        }
    }

    protected function paymentCount(string $table, string $payment): int
    {
        return DB::table($table)->where('payment_id', $payment)->count();
    }
}
