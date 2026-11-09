<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PostgresTestCase;

final class PostgresIntegrityTest extends PostgresTestCase
{
    private function assertDatabaseRejects(Closure $operation, string $sqlState = '23514'): void
    {
        try {
            DB::transaction($operation);
            $this->fail('The database accepted an operation that violates an invariant');
        } catch (\PDOException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? $exception->getCode());
        }
    }

    public function test_documents_are_frozen_after_any_associated_payment_exists(): void
    {
        $actor = $this->merchant();
        $document = $this->document($actor);
        $this->payment($actor, $document);
        $this->assertDatabaseRejects(fn () => DB::table('fiscal_documents')->where('id', $document['id'])->update(['cbs_amount_cents' => 1]));
        $this->assertSame(8000, (int) DB::table('fiscal_documents')->where('id', $document['id'])->value('cbs_amount_cents'));
    }

    public function test_financial_audit_and_idempotency_history_cannot_be_updated_or_deleted(): void
    {
        $actor = $this->merchant();
        $payment = $this->settled($actor);
        $entry = DB::table('ledger_entries')->where('payment_id', $payment['id'])->first();
        $event = DB::table('payment_events')->where('payment_id', $payment['id'])->first();
        $record = DB::table('idempotency_records')->first();
        $this->assertDatabaseRejects(fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['amount_cents' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('ledger_entries')->where('id', $entry->id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('payment_events')->where('id', $event->id)->update(['metadata_safe' => '{}']));
        $this->assertDatabaseRejects(fn () => DB::table('payment_events')->where('id', $event->id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('document_settlements')->where('payment_id', $payment['id'])->update(['created_at' => now()]));
        $this->assertDatabaseRejects(fn () => DB::table('idempotency_records')->where('id', $record->id)->update(['response_body' => '{}']));
        $this->assertDatabaseRejects(fn () => DB::table('idempotency_records')->where('id', $record->id)->delete());
        $this->assertSame(4, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertBalanced($payment['id']);
    }

    public function test_direct_invalid_monetary_insert_is_rejected_by_postgresql(): void
    {
        $actor = $this->merchant();
        $this->assertDatabaseRejects(fn () => DB::table('fiscal_documents')->insert([
            'id' => (string) Str::uuid(), 'merchant_id' => $actor['id'], 'reference' => 'DEMO-DOC-INVALIDSQL',
            'gross_amount_cents' => 100, 'cbs_amount_cents' => 70, 'ibs_amount_cents' => 40,
            'status' => 'ISSUED', 'created_at' => now(),
        ]));
        $this->assertSame(0, DB::table('fiscal_documents')->count());
    }

    public function test_unbalanced_settlement_is_rejected_at_commit_even_when_http_is_bypassed(): void
    {
        $actor = $this->merchant();
        $payment = $this->authorized($actor);
        $event = (string) Str::uuid();
        $this->assertDatabaseRejects(function () use ($payment, $event): void {
            DB::table('payment_events')->insert([
                'id' => $event, 'payment_id' => $payment['id'], 'event_type' => 'SETTLED',
                'previous_status' => 'AUTHORIZED', 'new_status' => 'SETTLED', 'occurred_at' => now(),
                'correlation_id' => (string) Str::uuid(), 'metadata_safe' => '{}',
            ]);
            DB::table('document_settlements')->insert([
                'fiscal_document_id' => $payment['fiscal_document_id'], 'payment_id' => $payment['id'],
                'settlement_event_id' => $event, 'created_at' => now(),
            ]);
            DB::table('ledger_entries')->insert([
                'id' => (string) Str::uuid(), 'payment_id' => $payment['id'], 'event_id' => $event,
                'account_code' => 'CLEARING', 'direction' => 'DEBIT', 'amount_cents' => 100000,
                'reversal_of_entry_id' => null, 'created_at' => now(),
            ]);
            DB::table('payments')->where('id', $payment['id'])->update([
                'status' => 'SETTLED', 'version' => DB::raw('version + 1'), 'updated_at' => now(),
            ]);
        });
        $this->assertSame('AUTHORIZED', DB::table('payments')->where('id', $payment['id'])->value('status'));
        $this->assertSame(0, DB::table('document_settlements')->count());
        $this->assertSame(0, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertSame(0, DB::table('payment_events')->where('id', $event)->count());
    }

    public function test_incomplete_idempotency_result_cannot_survive_commit(): void
    {
        $actor = $this->merchant();
        $id = (string) Str::uuid();
        $this->assertDatabaseRejects(fn () => DB::table('idempotency_records')->insert([
            'id' => $id, 'merchant_id' => $actor['id'], 'operation' => 'create_payment',
            'resource_scope' => 'create', 'key' => 'incomplete-demo-command',
            'request_hash' => hash('sha256', 'demo-uncommitted'),
        ]));
        $this->assertSame(0, DB::table('idempotency_records')->where('id', $id)->count());
    }

    public function test_a_permanent_document_claim_cannot_reference_a_nonfinancial_event(): void
    {
        $actor = $this->merchant();
        $created = $this->payment($actor, $this->document($actor));
        $payment = $created['payment'];
        $event = DB::table('payment_events')->where('payment_id', $payment['id'])->where('event_type', 'CREATED')->value('id');
        $this->assertNotNull($event);
        $this->assertDatabaseRejects(fn () => DB::table('document_settlements')->insert([
            'fiscal_document_id' => $payment['fiscal_document_id'], 'payment_id' => $payment['id'],
            'settlement_event_id' => $event, 'created_at' => now(),
        ]));
        $this->assertSame(0, DB::table('document_settlements')->count());
        $this->assertSame('CREATED', DB::table('payments')->where('id', $payment['id'])->value('status'));
    }

    public function test_document_uuid_is_immutable_even_without_an_associated_payment(): void
    {
        $actor = $this->merchant();
        $document = $this->document($actor);
        $replacement = (string) Str::uuid();
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertDatabaseRejects(fn () => DB::table('fiscal_documents')
            ->where('id', $document['id'])->update(['id' => $replacement]));
        $this->assertSame(1, DB::table('fiscal_documents')->where('id', $document['id'])->count());
        $this->assertSame(0, DB::table('fiscal_documents')->where('id', $replacement)->count());
    }
}
