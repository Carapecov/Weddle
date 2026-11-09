<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PostgresTestCase;

final class TransactionFailureTest extends PostgresTestCase
{
    public function test_t09_failure_after_settlement_entries_rolls_back_every_financial_effect_and_allows_retry(): void
    {
        $actor = $this->merchant();
        $payment = $this->authorized($actor);
        $key = (string) Str::uuid();
        config(['weddell.fail_at' => 'settlement_after_ledger']);
        $this->assertError($this->command($actor, $payment['id'], 'settle', $key), 503);
        $this->assertSame('AUTHORIZED', DB::table('payments')->where('id', $payment['id'])->value('status'));
        $this->assertSame(0, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertSame(0, DB::table('document_settlements')->count());
        $this->assertSame(0, DB::table('payment_events')->where('payment_id', $payment['id'])->where('event_type', 'SETTLED')->count());
        $this->assertSame(0, DB::table('idempotency_records')->where('key', $key)->count());
        config(['weddell.fail_at' => null]);
        $this->command($actor, $payment['id'], 'settle', $key)->assertOk()->assertJsonPath('status', 'SETTLED');
        $this->assertSame(4, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertSame(1, DB::table('idempotency_records')->where('key', $key)->count());
        $this->assertBalanced($payment['id']);
    }

    public function test_transient_settlement_failure_preserves_authorization_and_the_key_remains_retryable(): void
    {
        $actor = $this->merchant();
        $payment = $this->authorized($actor);
        $key = (string) Str::uuid();
        config(['weddell.failure_mode' => 'transient']);
        $this->assertError($this->command($actor, $payment['id'], 'settle', $key), 503);
        $this->assertSame('AUTHORIZED', DB::table('payments')->where('id', $payment['id'])->value('status'));
        $this->assertSame(0, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertSame(0, DB::table('idempotency_records')->where('key', $key)->count());
        config(['weddell.failure_mode' => 'none']);
        $this->command($actor, $payment['id'], 'settle', $key)->assertOk()->assertJsonPath('status', 'SETTLED');
        $this->assertSame(1, DB::table('document_settlements')->count());
    }

    public function test_terminal_settlement_failure_is_audited_without_financial_entries(): void
    {
        $actor = $this->merchant();
        $payment = $this->authorized($actor);
        $key = (string) Str::uuid();
        config(['weddell.failure_mode' => 'terminal']);
        $response = $this->command($actor, $payment['id'], 'settle', $key)->assertOk()
            ->assertJsonPath('status', 'FAILED')->assertJsonPath('failure_code', 'SETTLEMENT_TERMINAL_FAILURE');
        $this->assertContract($response->json(), 'Payment');
        config(['weddell.failure_mode' => 'none']);
        $replay = $this->command($actor, $payment['id'], 'settle', $key)->assertOk();
        $this->assertEquals($response->json(), $replay->json());
        $this->assertError($this->command($actor, $payment['id'], 'settle'), 409);
        $this->assertSame(0, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertSame(0, DB::table('document_settlements')->count());
        $this->assertSame(1, DB::table('payment_events')->where('payment_id', $payment['id'])->where('event_type', 'SETTLEMENT_FAILED')->count());
    }

    public function test_refund_failure_after_compensation_entries_rolls_back_and_can_be_retried(): void
    {
        $actor = $this->merchant();
        $payment = $this->settled($actor);
        $key = (string) Str::uuid();
        $originals = DB::table('ledger_entries')->where('payment_id', $payment['id'])->orderBy('id')->get();
        config(['weddell.fail_at' => 'refund_after_ledger']);
        $this->assertError($this->command($actor, $payment['id'], 'refund', $key), 503);
        $this->assertSame('SETTLED', DB::table('payments')->where('id', $payment['id'])->value('status'));
        $this->assertEquals($originals, DB::table('ledger_entries')->where('payment_id', $payment['id'])->orderBy('id')->get());
        $this->assertSame(0, DB::table('payment_events')->where('payment_id', $payment['id'])->where('event_type', 'REFUNDED')->count());
        $this->assertSame(0, DB::table('idempotency_records')->where('key', $key)->count());
        $this->assertSame(1, DB::table('document_settlements')->count());
        config(['weddell.fail_at' => null]);
        $this->command($actor, $payment['id'], 'refund', $key)->assertOk()->assertJsonPath('status', 'REFUNDED');
        $this->assertSame(8, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertBalanced($payment['id']);
    }

    public function test_test_hooks_are_inert_outside_testing_environment(): void
    {
        $actor = $this->merchant();
        $payment = $this->authorized($actor);
        config(['weddell.failure_mode' => 'terminal', 'weddell.fail_at' => 'settlement_after_ledger']);
        $originalEnvironment = $this->app['env'];
        try {
            $this->app['env'] = 'local';
            $this->command($actor, $payment['id'], 'settle')->assertOk()->assertJsonPath('status', 'SETTLED');
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
        $this->assertSame(4, $this->paymentCount('ledger_entries', $payment['id']));
    }
}
