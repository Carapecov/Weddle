<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\PostgresTestCase;

final class WebDemoTest extends PostgresTestCase
{
    /** @return array{merchant_id: string, credential_id: string} */
    private function enterDemo(): array
    {
        $this->webRequest('POST', '/web/session', [])
            ->assertOk()
            ->assertExactJson(['redirect' => '/app'])
            ->assertSessionHas('web_demo');

        $session = session('web_demo');
        $this->assertIsArray($session);

        return $session;
    }

    /** @return array{merchant_id: string, credential_id: string} */
    private function bindWebSession(array $actor): array
    {
        $credential = DB::table('demo_credentials')->where('token_hash', hash('sha256', $actor['token']))->first();
        $this->assertNotNull($credential);
        $session = ['merchant_id' => $actor['id'], 'credential_id' => $credential->id];
        $this->withSession(['web_demo' => $session]);

        return $session;
    }

    private function webRequest(string $method, string $uri, ?array $payload = null, ?string $key = null): TestResponse
    {
        return $this->api(null, $method, $uri, $payload, $key);
    }

    /** @return array{gross_amount_cents: int, cbs_amount_cents: int, ibs_amount_cents: int, scenario: string} */
    private function paymentInput(): array
    {
        return ['gross_amount_cents' => 100000, 'cbs_amount_cents' => 8000,
            'ibs_amount_cents' => 12000, 'scenario' => 'approved'];
    }

    public function test_public_pages_render_without_reflecting_query_html_or_exposing_credentials(): void
    {
        $actor = $this->merchant();
        $credential = DB::table('demo_credentials')->where('merchant_id', $actor['id'])->first();
        $untrusted = '<img src=x onerror=alert("web-demo-xss")>';

        foreach (['/', '/docs'] as $route) {
            $response = $this->get($route.'?message='.rawurlencode($untrusted));

            $response->assertOk()
                ->assertSee('Weddell Pay')
                ->assertDontSee($untrusted, false)
                ->assertDontSee($actor['token'], false)
                ->assertDontSee($credential->token_hash, false)
                ->assertDontSee($credential->id, false);
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_app_redirects_and_json_routes_return_401_without_a_demo_session(): void
    {
        $this->get('/app')->assertRedirect('/');

        $response = $this->webRequest('GET', '/web/overview');

        $this->assertError($response, 401, 'SESSION_REQUIRED');
        $this->assertDatabaseCount('merchants', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_session_entry_persists_six_examples_and_reuses_the_same_tenant(): void
    {
        $session = $this->enterDemo();
        $credential = DB::table('demo_credentials')->where('id', $session['credential_id'])->first();

        $this->assertSame(['merchant_id', 'credential_id'], array_keys($session));
        $this->assertNotNull($credential);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $credential->token_hash);
        $this->assertDatabaseHas('demo_credentials', [
            'id' => $session['credential_id'], 'merchant_id' => $session['merchant_id'],
            'role' => 'merchant', 'revoked_at' => null,
        ]);
        $this->assertDatabaseCount('merchants', 1);
        $this->assertDatabaseCount('demo_credentials', 1);
        $this->assertDatabaseCount('fiscal_documents', 6);
        $this->assertDatabaseCount('payments', 6);
        $this->assertDatabaseCount('ledger_entries', 16);
        $states = DB::table('payments')->selectRaw('status, count(*) AS count')->groupBy('status')
            ->get()->mapWithKeys(fn (object $row): array => [$row->status => (int) $row->count])->all();
        ksort($states);
        $this->assertSame(['AUTHORIZED' => 1, 'CREATED' => 1, 'FAILED' => 1, 'REFUNDED' => 1, 'SETTLED' => 2], $states);

        $this->webRequest('POST', '/web/session', [])->assertOk()->assertExactJson(['redirect' => '/app']);
        $this->assertSame($session, session('web_demo'));
        $this->assertDatabaseCount('merchants', 1);
        $this->assertDatabaseCount('demo_credentials', 1);
        $this->assertDatabaseCount('payments', 6);

        $this->get('/app')->assertOk()
            ->assertDontSee($session['credential_id'], false)
            ->assertDontSee($credential->token_hash, false);
    }

    public function test_revoked_demo_sessions_return_401_and_cannot_open_the_app(): void
    {
        $actor = $this->merchant();
        $session = $this->bindWebSession($actor);
        DB::table('demo_credentials')->where('id', $session['credential_id'])->update(['revoked_at' => now()]);

        $this->assertError($this->webRequest('GET', '/web/overview'), 401, 'SESSION_REQUIRED');

        $this->get('/app')->assertRedirect('/');
        $this->assertNull(session('web_demo'));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_web_sessions_cannot_read_or_transition_another_merchants_payment(): void
    {
        $owner = $this->merchant();
        $other = $this->merchant();
        $payment = $this->payment($other, $this->document($other))['payment'];
        $this->bindWebSession($owner);

        $this->assertError($this->webRequest('GET', '/web/payments/'.$payment['id']), 404);
        foreach (['authorize', 'settle', 'refund'] as $operation) {
            $this->assertError($this->webRequest('POST', '/web/payments/'.$payment['id'].'/'.$operation,
                [], (string) Str::uuid()), 404);
        }
        $this->assertDatabaseHas('payments', ['id' => $payment['id'], 'status' => 'CREATED']);
        $this->assertDatabaseCount('ledger_entries', 0);

        $foreignCredential = DB::table('demo_credentials')->where('merchant_id', $other['id'])->value('id');
        $this->withSession(['web_demo' => ['merchant_id' => $owner['id'], 'credential_id' => $foreignCredential]]);
        $this->assertError($this->webRequest('GET', '/web/overview'), 401, 'SESSION_REQUIRED');
    }

    public function test_overview_uses_actual_tenant_scoped_totals_and_seven_sao_paulo_days(): void
    {
        $this->travelTo('2026-11-03 01:30:00 UTC');
        try {
            $session = $this->enterDemo();
            $other = $this->merchant();
            $this->payment($other, $this->document($other, 90000000, 0, 0));

            $response = $this->webRequest('GET', '/web/overview')->assertOk();

            $expected = [
                'total_amount_cents' => 927700, 'settled_amount_cents' => 146900,
                'total_payments' => 6, 'authorized_count' => 1, 'failed_count' => 1,
                'refunded_count' => 1, 'settled_count' => 2, 'cbs_amount_cents' => 11752,
                'ibs_amount_cents' => 17628, 'merchant_net_cents' => 117520,
            ];
            $actual = $response->json('summary');
            ksort($expected);
            ksort($actual);
            $this->assertSame($expected, $actual);
            $days = $response->json('daily');
            $this->assertSame(['2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30',
                '2026-10-31', '2026-11-01', '2026-11-02'], array_column($days, 'date'));
            foreach (array_slice($days, 0, 6) as $day) {
                $this->assertSame(0, $day['amount_cents']);
                $this->assertSame(0, $day['count']);
            }
            $this->assertSame(['date' => '2026-11-02', 'amount_cents' => 927700, 'count' => 6], $days[6]);
            $response->assertJsonCount(6, 'recent');
            foreach ($response->json('recent') as $payment) {
                $this->assertSame($session['merchant_id'], $payment['merchant_id']);
                $this->assertContract($payment, 'Payment');
            }
        } finally {
            $this->travelBack();
        }
    }

    public function test_payment_listing_filters_searches_and_paginates_only_its_tenant(): void
    {
        $session = $this->enterDemo();
        $other = $this->merchant();
        $foreign = $this->payment($other, $this->document($other))['payment'];
        $created = DB::table('payments')->where('merchant_id', $session['merchant_id'])->where('status', 'CREATED')->first();

        $settled = $this->webRequest('GET', '/web/payments?status=SETTLED')->assertOk();

        $settled->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
        foreach ($settled->json('data') as $payment) {
            $this->assertSame('SETTLED', $payment['status']);
            $this->assertSame($session['merchant_id'], $payment['merchant_id']);
        }
        $this->webRequest('GET', '/web/payments?search='.$created->id)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $created->id);
        $this->webRequest('GET', '/web/payments?status=SETTLED&search='.$created->id)->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->webRequest('GET', '/web/payments?search='.$foreign['id'])->assertOk()->assertJsonPath('meta.total', 0);
        $first = $this->webRequest('GET', '/web/payments?page=1&limit=2')->assertOk();
        $second = $this->webRequest('GET', '/web/payments?page=2&limit=2')->assertOk();
        $this->assertSame(['current_page' => 1, 'last_page' => 3, 'total' => 6, 'per_page' => 2], $first->json('meta'));
        $first->assertJsonCount(2, 'data');
        $second->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2);
        $this->assertSame([], array_values(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'))));
    }

    public function test_invalid_filters_return_422_and_search_text_cannot_change_the_query(): void
    {
        $this->enterDemo();

        foreach (['status=UNKNOWN', 'page=0', 'limit=51', 'sort=status', 'search='.str_repeat('x', 81)] as $query) {
            $this->assertError($this->webRequest('GET', '/web/payments?'.$query), 422, 'VALIDATION_ERROR');
        }
        $response = $this->webRequest('GET', '/web/payments?search='.rawurlencode("' OR 1=1 --"))->assertOk();

        $response->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('payments', 6);
    }

    public function test_web_create_returns_201_and_replay_or_conflict_never_leave_orphan_documents(): void
    {
        $actor = $this->merchant();
        $this->bindWebSession($actor);
        $payload = $this->paymentInput();
        $key = (string) Str::uuid();

        $created = $this->webRequest('POST', '/web/payments', $payload, $key)->assertCreated();

        $payment = $created->json();
        $this->assertContract($payment, 'Payment');
        $this->assertSame($actor['id'], $payment['merchant_id']);
        $this->assertDatabaseHas('fiscal_documents', [
            'id' => $payment['fiscal_document_id'], 'merchant_id' => $actor['id'],
            'gross_amount_cents' => 100000, 'cbs_amount_cents' => 8000, 'ibs_amount_cents' => 12000,
        ]);
        $this->assertDatabaseCount('fiscal_documents', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('ledger_entries', 0);

        $replay = $this->webRequest('POST', '/web/payments', $payload, $key)->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');
        $originalBody = $created->json();
        $replayedBody = $replay->json();
        ksort($originalBody);
        ksort($replayedBody);
        $this->assertSame($originalBody, $replayedBody);
        $this->assertSame($created->headers->get('Location'), $replay->headers->get('Location'));
        $this->assertError($this->webRequest('POST', '/web/payments',
            array_replace($payload, ['gross_amount_cents' => 100001]), $key), 409);
        $this->assertDatabaseCount('fiscal_documents', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_events', 1);
    }

    public function test_web_create_returns_400_without_a_key_and_422_for_invalid_fields_without_writes(): void
    {
        $actor = $this->merchant();
        $this->bindWebSession($actor);
        $payload = $this->paymentInput();
        $invalid = [
            array_replace($payload, ['cbs_amount_cents' => 90000, 'ibs_amount_cents' => 20000]),
            array_replace($payload, ['gross_amount_cents' => '100000']),
            array_replace($payload, ['merchant_id' => (string) Str::uuid()]),
            array_replace($payload, ['scenario' => 'random']),
        ];

        $this->assertError($this->webRequest('POST', '/web/payments', $payload), 400);
        foreach ($invalid as $command) {
            $this->assertError($this->webRequest('POST', '/web/payments', $command, (string) Str::uuid()), 422);
        }
        $this->assertDatabaseCount('fiscal_documents', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_events', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function test_failure_after_web_document_insert_rolls_back_and_the_same_key_can_retry(): void
    {
        $actor = $this->merchant();
        $this->bindWebSession($actor);
        $key = (string) Str::uuid();
        config(['weddell.fail_at' => 'web_after_document']);

        try {
            $this->assertError($this->webRequest('POST', '/web/payments', $this->paymentInput(), $key), 503);
            $this->assertDatabaseCount('fiscal_documents', 0);
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('payment_events', 0);
            $this->assertDatabaseCount('idempotency_records', 0);
        } finally {
            config(['weddell.fail_at' => null]);
        }

        $this->webRequest('POST', '/web/payments', $this->paymentInput(), $key)->assertCreated();
        $this->assertDatabaseCount('fiscal_documents', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('idempotency_records', 1);
    }

    public function test_web_actions_use_the_core_lifecycle_and_keep_original_replay_after_refund(): void
    {
        $actor = $this->merchant();
        $this->bindWebSession($actor);
        $created = $this->webRequest('POST', '/web/payments', $this->paymentInput(), (string) Str::uuid())->assertCreated();
        $id = $created->json('id');
        $settleKey = (string) Str::uuid();

        $this->webRequest('POST', '/web/payments/'.$id.'/authorize', [], (string) Str::uuid())
            ->assertOk()->assertJsonPath('status', 'AUTHORIZED')->assertJsonPath('split', null);
        $settled = $this->webRequest('POST', '/web/payments/'.$id.'/settle', [], $settleKey)->assertOk()
            ->assertJsonPath('status', 'SETTLED')->assertJsonPath('split.merchant_net_cents', 80000);
        $this->webRequest('POST', '/web/payments/'.$id.'/refund', [], (string) Str::uuid())
            ->assertOk()->assertJsonPath('status', 'REFUNDED');
        $replay = $this->webRequest('POST', '/web/payments/'.$id.'/settle', [], $settleKey)->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('status', 'SETTLED');
        $this->assertSame($settled->json('id'), $replay->json('id'));
        $this->assertSame(80000, $replay->json('split.merchant_net_cents'));
        $detail = $this->webRequest('GET', '/web/payments/'.$id)->assertOk()
            ->assertJsonPath('payment.status', 'REFUNDED')->assertJsonCount(8, 'ledger')->assertJsonCount(4, 'events');
        $this->assertContract($detail->json('payment'), 'Payment');
        $this->assertContract($detail->json('document'), 'FiscalDocument');
        $this->assertDatabaseHas('payments', ['id' => $id, 'status' => 'REFUNDED']);
        $this->assertSame(8, $this->paymentCount('ledger_entries', $id));
        $this->assertBalanced($id);
        $this->assertError($this->webRequest('POST', '/web/payments/'.$id.'/settle', [], (string) Str::uuid()), 409);
    }

    public function test_web_declined_scenario_returns_failed_without_financial_entries(): void
    {
        $actor = $this->merchant();
        $this->bindWebSession($actor);
        $payload = array_replace($this->paymentInput(), ['scenario' => 'declined']);
        $created = $this->webRequest('POST', '/web/payments', $payload, (string) Str::uuid())->assertCreated();

        $this->webRequest('POST', '/web/payments/'.$created->json('id').'/authorize', [], (string) Str::uuid())
            ->assertOk()->assertJsonPath('status', 'FAILED')->assertJsonPath('failure_code', 'AUTHORIZATION_DECLINED');

        $this->assertDatabaseHas('payments', ['id' => $created->json('id'), 'status' => 'FAILED']);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('document_settlements', 0);
    }

    public function test_examples_are_persisted_once_and_repeated_requests_create_no_duplicates(): void
    {
        $actor = $this->merchant();
        $this->bindWebSession($actor);

        $this->webRequest('POST', '/web/examples', [])->assertOk()->assertExactJson(['seeded' => true, 'created_count' => 6]);
        $this->webRequest('POST', '/web/examples', [])->assertOk()->assertExactJson(['seeded' => true, 'created_count' => 0]);

        $this->assertDatabaseCount('payments', 6);
        $this->assertDatabaseCount('fiscal_documents', 6);
        $this->assertDatabaseCount('ledger_entries', 16);
        $this->webRequest('GET', '/web/payments')->assertOk()->assertJsonPath('meta.total', 6);
    }

    public function test_logout_revokes_the_credential_and_invalidates_the_demo_session(): void
    {
        $actor = $this->merchant();
        $session = $this->bindWebSession($actor);
        $originalSessionId = session()->getId();

        $this->webRequest('POST', '/web/logout', [])->assertOk()->assertExactJson(['redirect' => '/'])
            ->assertSessionMissing('web_demo');

        $this->assertNotNull(DB::table('demo_credentials')->where('id', $session['credential_id'])->value('revoked_at'));
        $this->assertNotSame($originalSessionId, session()->getId());
        $this->assertError($this->webRequest('GET', '/web/overview'), 401, 'SESSION_REQUIRED');
        $this->get('/app')->assertRedirect('/');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_demo_entry_returns_403_for_nonlocal_and_cross_origin_requests(): void
    {
        $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        $remote = $this->call('POST', '/web/session', [], [], [], [
            ...$headers, 'REMOTE_ADDR' => '203.0.113.10',
        ], '{}');
        $this->assertError($remote, 403, 'LOCAL_DEMO_ONLY');

        $crossOrigin = $this->call('POST', '/web/session', [], [], [], [
            ...$headers, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_ORIGIN' => 'https://example.invalid',
        ], '{}');
        $this->assertError($crossOrigin, 403, 'ORIGIN_FORBIDDEN');
        $this->assertDatabaseCount('merchants', 0);
        $this->assertDatabaseCount('demo_credentials', 0);
        $this->assertDatabaseCount('payments', 0);
    }
}
