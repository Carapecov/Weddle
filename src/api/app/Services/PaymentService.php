<?php

namespace App\Services;

use App\Domain\ApiProblem;
use App\Support\Representations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PaymentService
{
    public function ownedPayment(string $id, string $merchant, bool $lock = false): object
    {
        $query = DB::table('payments')->where('id', $id)->where('merchant_id', $merchant);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) {
            throw new ApiProblem(404, 'RESOURCE_NOT_FOUND', 'Recurso não encontrado.');
        }

        return $row;
    }

    public function ownedDocument(string $id, string $merchant, bool $lock = false): object
    {
        $query = DB::table('fiscal_documents')->where('id', $id)->where('merchant_id', $merchant);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) {
            throw new ApiProblem(404, 'RESOURCE_NOT_FOUND', 'Recurso não encontrado.');
        }

        return $row;
    }

    private function event(object $payment, string $type, ?string $previous, string $next, array $metadata = []): string
    {
        $id = (string) Str::uuid();
        DB::table('payment_events')->insert([
            'id' => $id, 'payment_id' => $payment->id, 'event_type' => $type,
            'previous_status' => $previous, 'new_status' => $next, 'occurred_at' => now('UTC'),
            'correlation_id' => request()->attributes->get('correlation_id') ?? (string) Str::uuid(),
            'metadata_safe' => json_encode((object) $metadata, JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }

    private function change(object $payment, string $status, ?string $failure = null): object
    {
        DB::table('payments')->where('id', $payment->id)->where('version', $payment->version)->update([
            'status' => $status, 'failure_code' => $failure, 'version' => (int) $payment->version + 1,
            'updated_at' => now('UTC'),
        ]);

        return DB::table('payments')->where('id', $payment->id)->first();
    }

    private function requireState(object $payment, string $state): void
    {
        if ($payment->status !== $state) {
            throw new ApiProblem(409, 'STATE_CONFLICT', 'Operação incompatível com o estado atual.');
        }
    }

    private function testMode(): string
    {
        if (! app()->environment('testing')) {
            return 'none';
        }
        $mode = config('weddell.failure_mode', 'none');

        return in_array($mode, ['none', 'transient', 'terminal'], true) ? $mode : 'none';
    }

    private function response(object $payment, int $status = 200, array $headers = []): array
    {
        return ['status' => $status, 'body' => Representations::payment($payment), 'headers' => $headers];
    }

    public function create(string $merchant, array $command): array
    {
        $document = $this->ownedDocument($command['fiscal_document_id'], $merchant, true);
        if (DB::table('document_settlements')->where('fiscal_document_id', $document->id)->exists()) {
            throw new ApiProblem(409, 'DOCUMENT_ALREADY_SETTLED', 'Documento já possui liquidação histórica.');
        }
        if ((int) $command['amount_cents'] !== (int) $document->gross_amount_cents) {
            throw new ApiProblem(422, 'VALIDATION_ERROR', 'Valor deve ser igual ao bruto do documento.');
        }
        $id = (string) Str::uuid();
        $time = now('UTC');
        DB::table('payments')->insert([
            'id' => $id, 'merchant_id' => $merchant, 'fiscal_document_id' => $document->id,
            'amount_cents' => $command['amount_cents'], 'currency' => 'BRL', 'scenario' => $command['scenario'],
            'status' => 'CREATED', 'failure_code' => null, 'version' => 1, 'created_at' => $time, 'updated_at' => $time,
        ]);
        $payment = $this->ownedPayment($id, $merchant);
        $this->event($payment, 'CREATED', null, 'CREATED', ['operation' => 'create_payment']);

        return $this->response($payment, 201, ['Location' => '/api/v1/payments/'.$id]);
    }

    public function transition(string $merchant, string $id, string $operation): array
    {
        $initial = $this->ownedPayment($id, $merchant);
        $document = $this->ownedDocument($initial->fiscal_document_id, $merchant, true);
        $payment = $this->ownedPayment($id, $merchant, true);
        if ($operation === 'authorize_payment') {
            $this->requireState($payment, 'CREATED');
            $approved = $payment->scenario === 'approved';
            $status = $approved ? 'AUTHORIZED' : 'FAILED';
            $failure = $approved ? null : 'AUTHORIZATION_DECLINED';
            $this->event($payment, $approved ? 'AUTHORIZED' : 'AUTHORIZATION_DECLINED', 'CREATED', $status,
                array_filter(['operation' => $operation, 'failure_code' => $failure], fn ($v) => $v !== null));

            return $this->response($this->change($payment, $status, $failure));
        }
        if ($operation === 'settle_payment') {
            $this->requireState($payment, 'AUTHORIZED');
            if (DB::table('document_settlements')->where('fiscal_document_id', $document->id)->exists()) {
                throw new ApiProblem(409, 'DOCUMENT_ALREADY_SETTLED', 'Documento já possui liquidação histórica.');
            }
            $mode = $this->testMode();
            if ($mode === 'transient') {
                throw new ApiProblem(503, 'TEMPORARY_UNAVAILABLE', 'Falha transitória simulada. Repita com a mesma chave.');
            }
            if ($mode === 'terminal') {
                $this->event($payment, 'SETTLEMENT_FAILED', 'AUTHORIZED', 'FAILED',
                    ['operation' => $operation, 'failure_code' => 'SETTLEMENT_TERMINAL_FAILURE']);

                return $this->response($this->change($payment, 'FAILED', 'SETTLEMENT_TERMINAL_FAILURE'));
            }
            $event = $this->event($payment, 'SETTLED', 'AUTHORIZED', 'SETTLED', ['operation' => $operation]);
            DB::table('document_settlements')->insert([
                'fiscal_document_id' => $document->id, 'payment_id' => $payment->id,
                'settlement_event_id' => $event, 'created_at' => now('UTC'),
            ]);
            $amounts = [
                ['CLEARING', 'DEBIT', (int) $payment->amount_cents],
                ['MERCHANT_PAYABLE', 'CREDIT', (int) $payment->amount_cents - (int) $document->cbs_amount_cents - (int) $document->ibs_amount_cents],
                ['CBS_PAYABLE', 'CREDIT', (int) $document->cbs_amount_cents],
                ['IBS_PAYABLE', 'CREDIT', (int) $document->ibs_amount_cents],
            ];
            foreach ($amounts as [$account, $direction, $amount]) {
                if ($amount === 0) {
                    continue;
                }
                DB::table('ledger_entries')->insert([
                    'id' => (string) Str::uuid(), 'payment_id' => $payment->id, 'event_id' => $event,
                    'account_code' => $account, 'direction' => $direction, 'amount_cents' => $amount,
                    'reversal_of_entry_id' => null, 'created_at' => now('UTC'),
                ]);
            }
            if (app()->environment('testing') && config('weddell.fail_at') === 'settlement_after_ledger') {
                throw new ApiProblem(503, 'TEMPORARY_UNAVAILABLE', 'Falha simulada antes do commit.');
            }

            return $this->response($this->change($payment, 'SETTLED'));
        }
        $this->requireState($payment, 'SETTLED');
        $event = $this->event($payment, 'REFUNDED', 'SETTLED', 'REFUNDED', ['operation' => 'refund_payment']);
        $original = DB::table('ledger_entries')->join('payment_events', 'payment_events.id', '=', 'ledger_entries.event_id')
            ->where('ledger_entries.payment_id', $payment->id)->where('payment_events.event_type', 'SETTLED')
            ->select('ledger_entries.*')->get();
        foreach ($original as $entry) {
            DB::table('ledger_entries')->insert([
                'id' => (string) Str::uuid(), 'payment_id' => $payment->id, 'event_id' => $event,
                'account_code' => $entry->account_code, 'direction' => $entry->direction === 'DEBIT' ? 'CREDIT' : 'DEBIT',
                'amount_cents' => $entry->amount_cents, 'reversal_of_entry_id' => $entry->id, 'created_at' => now('UTC'),
            ]);
        }
        if (app()->environment('testing') && config('weddell.fail_at') === 'refund_after_ledger') {
            throw new ApiProblem(503, 'TEMPORARY_UNAVAILABLE', 'Falha simulada antes do commit de estorno.');
        }

        return $this->response($this->change($payment, 'REFUNDED'));
    }
}
