<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PostgresTestCase;

final class PaymentLifecycleTest extends PostgresTestCase
{
    public function test_t01_t14_settlement_preserves_the_total_and_balances_each_event(): void
    {
        $actor = $this->merchant();
        $created = $this->payment($actor, $this->document($actor));
        $id = $created['payment']['id'];
        $this->assertNull($created['payment']['split']);
        $this->assertSame(0, $this->paymentCount('ledger_entries', $id));

        $this->command($actor, $id, 'authorize')->assertOk()->assertJsonPath('split', null);
        $settled = $this->command($actor, $id, 'settle')->assertOk()->assertJsonPath('status', 'SETTLED');
        $this->assertContract($settled->json(), 'Payment');
        $this->assertSame(['merchant_net_cents' => 80000, 'cbs_cents' => 8000, 'ibs_cents' => 12000], $settled->json('split'));
        $this->assertSame(100000, array_sum($settled->json('split')));
        $this->assertSame(4, $this->paymentCount('ledger_entries', $id));
        $this->assertSame(1, DB::table('document_settlements')->where('payment_id', $id)->count());
        $this->assertBalanced($id);

        $ledger = $this->api($actor, 'GET', '/api/v1/payments/'.$id.'/ledger')->assertOk();
        $this->assertContract($ledger->json(), 'LedgerPage');
        $entries = collect($ledger->json('data'))->keyBy('account_code');
        $this->assertSame('DEBIT', $entries['CLEARING']['direction']);
        $this->assertSame(100000, $entries['CLEARING']['amount_cents']);
        foreach (['MERCHANT_PAYABLE' => 80000, 'CBS_PAYABLE' => 8000, 'IBS_PAYABLE' => 12000] as $account => $amount) {
            $this->assertSame('CREDIT', $entries[$account]['direction']);
            $this->assertSame($amount, $entries[$account]['amount_cents']);
        }
        $events = $this->api($actor, 'GET', '/api/v1/payments/'.$id.'/events')->assertOk();
        $this->assertContract($events->json(), 'EventPage');
        $this->assertEqualsCanonicalizing(['CREATED', 'AUTHORIZED', 'SETTLED'], array_column($events->json('data'), 'event_type'));
    }

    public function test_t02_rejects_taxes_exceeding_gross_without_persisting_a_document(): void
    {
        $actor = $this->merchant();
        $response = $this->api($actor, 'POST', '/api/v1/fiscal-documents', [
            'merchant_id' => $actor['id'], 'reference' => 'DEMO-DOC-INVALID',
            'gross_amount_cents' => 100, 'cbs_amount_cents' => 70, 'ibs_amount_cents' => 31,
        ]);
        $this->assertError($response, 422);
        $this->assertSame(0, DB::table('fiscal_documents')->count());
        $this->assertSame(0, DB::table('ledger_entries')->count());
    }

    public function test_t03_declined_authorization_is_deterministic_and_never_posts_money(): void
    {
        $actor = $this->merchant();
        $payment = $this->payment($actor, $this->document($actor), 'declined')['payment'];
        $key = (string) Str::uuid();
        $failed = $this->command($actor, $payment['id'], 'authorize', $key)->assertOk()
            ->assertJsonPath('status', 'FAILED')->assertJsonPath('failure_code', 'AUTHORIZATION_DECLINED');
        $this->assertContract($failed->json(), 'Payment');
        $replay = $this->command($actor, $payment['id'], 'authorize', $key)->assertOk();
        $this->assertEquals($failed->json(), $replay->json());
        $this->assertError($this->command($actor, $payment['id'], 'settle'), 409);
        $this->assertSame(0, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertSame(1, DB::table('payment_events')->where('payment_id', $payment['id'])->where('event_type', 'AUTHORIZATION_DECLINED')->count());
    }

    #[DataProvider('invalidTransitions')]
    public function test_t04_rejects_invalid_transitions_without_new_financial_entries(string $state, string $command): void
    {
        $actor = $this->merchant();
        $created = $this->payment($actor, $this->document($actor), $state === 'FAILED' ? 'declined' : 'approved');
        $id = $created['payment']['id'];
        if ($state !== 'CREATED') {
            $this->command($actor, $id, 'authorize')->assertOk();
        }
        if (in_array($state, ['SETTLED', 'REFUNDED'], true)) {
            $this->command($actor, $id, 'settle')->assertOk();
        }
        if ($state === 'REFUNDED') {
            $this->command($actor, $id, 'refund')->assertOk();
        }
        $before = $this->paymentCount('ledger_entries', $id);
        $this->assertError($this->command($actor, $id, $command), 409);
        $this->assertSame($before, $this->paymentCount('ledger_entries', $id));
        $this->api($actor, 'GET', '/api/v1/payments/'.$id)->assertOk()->assertJsonPath('status', $state);
    }

    public static function invalidTransitions(): iterable
    {
        yield 'settle-created' => ['CREATED', 'settle'];
        yield 'refund-created' => ['CREATED', 'refund'];
        yield 'refund-authorized' => ['AUTHORIZED', 'refund'];
        yield 'authorize-authorized-new-key' => ['AUTHORIZED', 'authorize'];
        yield 'settle-failed' => ['FAILED', 'settle'];
        yield 'authorize-failed' => ['FAILED', 'authorize'];
        yield 'authorize-settled' => ['SETTLED', 'authorize'];
        yield 'settle-refunded' => ['REFUNDED', 'settle'];
        yield 'refund-refunded-new-key' => ['REFUNDED', 'refund'];
    }

    public function test_t05_t07_t11_replay_preserves_original_responses_after_refund(): void
    {
        $actor = $this->merchant();
        $created = $this->payment($actor, $this->document($actor));
        $id = $created['payment']['id'];
        $responses = [];
        foreach (['authorize', 'settle', 'refund'] as $command) {
            $key = (string) Str::uuid();
            $responses[$command] = ['key' => $key, 'response' => $this->command($actor, $id, $command, $key)->assertOk()];
        }
        $createReplay = $this->api($actor, 'POST', '/api/v1/payments', array_reverse($created['payload'], true), $created['key'])->assertCreated();
        $this->assertEquals($created['response']->json(), $createReplay->json());
        $this->assertSame($created['response']->headers->get('Location'), $createReplay->headers->get('Location'));
        $createReplay->assertHeader('Idempotency-Replayed', 'true');
        foreach ($responses as $command => $item) {
            $replay = $this->command($actor, $id, $command, $item['key'])->assertOk();
            $replay->assertHeader('Idempotency-Replayed', 'true');
            $this->assertEquals($item['response']->json(), $replay->json(), $command.' must replay its historic result');
            $this->assertError($this->command($actor, $id, $command), 409);
        }
        $this->api($actor, 'GET', '/api/v1/payments/'.$id)->assertOk()->assertJsonPath('status', 'REFUNDED')
            ->assertJsonPath('split.merchant_net_cents', 80000);
        $this->assertSame(8, $this->paymentCount('ledger_entries', $id));
        $this->assertSame(4, $this->paymentCount('payment_events', $id));
        $this->assertBalanced($id);
    }

    public function test_t08_same_key_with_different_valid_payload_conflicts(): void
    {
        $actor = $this->merchant();
        $created = $this->payment($actor, $this->document($actor));
        $changed = array_replace($created['payload'], ['scenario' => 'declined']);
        $this->assertError($this->api($actor, 'POST', '/api/v1/payments', $changed, $created['key']), 409);
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame('approved', DB::table('payments')->value('scenario'));
    }

    public function test_t10_refund_creates_exact_compensation_and_preserves_original_entries(): void
    {
        $actor = $this->merchant();
        $payment = $this->settled($actor);
        $originals = DB::table('ledger_entries')->where('payment_id', $payment['id'])->get()->keyBy('id');
        $refund = $this->command($actor, $payment['id'], 'refund')->assertOk()->assertJsonPath('status', 'REFUNDED');
        $this->assertContract($refund->json(), 'Payment');
        $this->assertEquals($payment['split'], $refund->json('split'));
        $reversals = DB::table('ledger_entries')->where('payment_id', $payment['id'])->whereNotNull('reversal_of_entry_id')->get();
        $this->assertCount($originals->count(), $reversals);
        foreach ($reversals as $reversal) {
            $original = $originals[$reversal->reversal_of_entry_id];
            $this->assertSame($original->account_code, $reversal->account_code);
            $this->assertSame((int) $original->amount_cents, (int) $reversal->amount_cents);
            $this->assertSame($original->direction === 'DEBIT' ? 'CREDIT' : 'DEBIT', $reversal->direction);
            $this->assertEquals($original, DB::table('ledger_entries')->where('id', $original->id)->first());
        }
        $this->assertBalanced($payment['id']);
    }

    #[DataProvider('validMoneyCases')]
    public function test_t13_zero_and_boundary_values_preserve_exact_cents(int $gross, int $cbs, int $ibs, int $entries): void
    {
        $actor = $this->merchant();
        $payment = $this->settled($actor, $this->document($actor, $gross, $cbs, $ibs));
        $this->assertSame($gross, $payment['gross_amount_cents']);
        $this->assertSame($gross - $cbs - $ibs, $payment['split']['merchant_net_cents']);
        $this->assertSame($cbs, $payment['split']['cbs_cents']);
        $this->assertSame($ibs, $payment['split']['ibs_cents']);
        $this->assertSame($entries, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertBalanced($payment['id']);
        $this->command($actor, $payment['id'], 'refund')->assertOk();
        $this->assertSame(2 * $entries, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertBalanced($payment['id']);
    }

    public static function validMoneyCases(): iterable
    {
        yield 'one-cent-no-tax' => [1, 0, 0, 2];
        yield 'zero-net-only-cbs' => [100, 100, 0, 2];
        yield 'zero-net-only-ibs' => [100, 0, 100, 2];
        yield 'odd-cents' => [101, 1, 99, 4];
        yield 'maximum' => [1000000000000, 400000000000, 100000000000, 4];
    }

    public function test_one_document_can_only_be_settled_once_even_after_refund(): void
    {
        $actor = $this->merchant();
        $document = $this->document($actor);
        $first = $this->authorized($actor, $document);
        $second = $this->authorized($actor, $document);
        $this->command($actor, $first['id'], 'settle')->assertOk();
        $this->assertError($this->command($actor, $second['id'], 'settle'), 409);
        $this->command($actor, $first['id'], 'refund')->assertOk();
        $this->assertError($this->command($actor, $second['id'], 'settle'), 409);
        $payload = ['merchant_id' => $actor['id'], 'fiscal_document_id' => $document['id'],
            'amount_cents' => 100000, 'currency' => 'BRL', 'scenario' => 'approved'];
        $this->assertError($this->api($actor, 'POST', '/api/v1/payments', $payload, (string) Str::uuid()), 409);
        $this->assertSame(1, DB::table('document_settlements')->count());
        $this->assertSame(0, $this->paymentCount('ledger_entries', $second['id']));
    }

    public function test_declined_attempt_does_not_consume_the_document(): void
    {
        $actor = $this->merchant();
        $document = $this->document($actor);
        $declined = $this->payment($actor, $document, 'declined')['payment'];
        $this->command($actor, $declined['id'], 'authorize')->assertOk()->assertJsonPath('status', 'FAILED');
        $this->settled($actor, $document);
        $this->assertSame(2, DB::table('payments')->count());
        $this->assertSame(1, DB::table('document_settlements')->count());
    }

    public function test_cached_business_error_replays_its_original_body_after_the_state_changes(): void
    {
        $actor = $this->merchant();
        $payment = $this->payment($actor, $this->document($actor))['payment'];
        $key = (string) Str::uuid();
        $originalError = $this->command($actor, $payment['id'], 'settle', $key);
        $this->assertError($originalError, 409);
        $this->command($actor, $payment['id'], 'authorize')->assertOk();
        $this->command($actor, $payment['id'], 'settle')->assertOk()->assertJsonPath('status', 'SETTLED');
        $replay = $this->command($actor, $payment['id'], 'settle', $key)->assertHeader('Idempotency-Replayed', 'true');
        $this->assertError($replay, 409);
        $this->assertEquals($originalError->json(), $replay->json());
        $this->assertNotSame($originalError->headers->get('X-Correlation-ID'), $replay->headers->get('X-Correlation-ID'));
        $this->api($actor, 'GET', '/api/v1/payments/'.$payment['id'])->assertOk()->assertJsonPath('status', 'SETTLED');
        $this->assertSame(4, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertSame(1, DB::table('document_settlements')->count());
    }
}
