<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiProblem;
use App\Http\Controllers\Controller;
use App\Services\IdempotencyService;
use App\Services\PaymentService;
use App\Support\Payload;
use App\Support\Representations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments, private IdempotencyService $idempotency) {}

    public function merchant(Request $request)
    {
        $data = Payload::validate($request, [
            'name' => ['required', 'string', 'regex:/^DEMO-[A-Z0-9_-]{3,48}$/D'],
            'virtual_account_id' => ['required', 'string', 'regex:/^DEMO-ACCOUNT-[A-Z0-9_-]{3,48}$/D'],
        ]);
        $id = (string) Str::uuid();
        DB::table('merchants')->insert([...$data, 'id' => $id, 'created_at' => now('UTC')]);

        return response()->json(Representations::merchant(DB::table('merchants')->where('id', $id)->first()), 201);
    }

    public function document(Request $request)
    {
        $data = Payload::validate($request, [
            'merchant_id' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'regex:/^DEMO-DOC-[A-Z0-9_-]{3,48}$/D'],
            'gross_amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'cbs_amount_cents' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'ibs_amount_cents' => ['required', 'integer', 'min:0', 'max:1000000000000'],
        ], ['gross_amount_cents', 'cbs_amount_cents', 'ibs_amount_cents']);
        Payload::tenant($request, $data['merchant_id']);
        if ($data['cbs_amount_cents'] > $data['gross_amount_cents'] - $data['ibs_amount_cents']) {
            throw new ApiProblem(422, 'VALIDATION_ERROR', 'CBS + IBS não pode superar o bruto.');
        }
        $id = (string) Str::uuid();
        DB::table('fiscal_documents')->insert([...$data, 'id' => $id, 'status' => 'ISSUED', 'created_at' => now('UTC')]);

        return response()->json(Representations::document(DB::table('fiscal_documents')->where('id', $id)->first()), 201);
    }

    public function create(Request $request)
    {
        $key = Payload::key($request);
        $data = Payload::validate($request, [
            'merchant_id' => ['required', 'uuid'], 'fiscal_document_id' => ['required', 'uuid'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'currency' => ['required', 'string', 'in:BRL'], 'scenario' => ['required', 'string', 'in:approved,declined'],
        ], ['amount_cents']);
        Payload::tenant($request, $data['merchant_id']);
        $merchant = $request->attributes->get('merchant_id');
        // Ownership is checked before any idempotency result is read.
        $this->payments->ownedDocument($data['fiscal_document_id'], $merchant);
        $result = $this->idempotency->run($merchant, 'create_payment', 'create', $key, $data,
            fn () => $this->payments->create($merchant, $data));

        return $this->respond($result);
    }

    public function authorize(Request $request, string $id)
    {
        return $this->transition($request, $id, 'authorize_payment');
    }

    public function settle(Request $request, string $id)
    {
        return $this->transition($request, $id, 'settle_payment');
    }

    public function refund(Request $request, string $id)
    {
        return $this->transition($request, $id, 'refund_payment');
    }

    private function transition(Request $request, string $id, string $operation)
    {
        $key = Payload::key($request);
        $data = Payload::empty($request);
        $merchant = $request->attributes->get('merchant_id');
        $id = strtolower($id);
        $this->payments->ownedPayment($id, $merchant);

        return $this->respond($this->idempotency->run($merchant, $operation, $id, $key, $data,
            fn () => $this->payments->transition($merchant, $id, $operation)));
    }

    private function respond(array $result)
    {
        return response()->json($result['body'], $result['status'],
            [...($result['headers'] ?? []), 'Idempotency-Replayed' => $result['replayed'] ? 'true' : 'false']);
    }

    public function show(Request $request, string $id)
    {
        Payload::empty($request);

        return response()->json(Representations::payment(
            $this->payments->ownedPayment($id, $request->attributes->get('merchant_id'))));
    }

    public function ledger(Request $request, string $id)
    {
        return $this->page($request, $id, 'ledger_entries', 'created_at');
    }

    public function events(Request $request, string $id)
    {
        return $this->page($request, $id, 'payment_events', 'occurred_at');
    }

    private function page(Request $request, string $id, string $table, string $timestamp)
    {
        Payload::empty($request);
        $payment = $this->payments->ownedPayment($id, $request->attributes->get('merchant_id'));
        if (array_diff(array_keys($request->query()), ['limit', 'cursor'])) {
            throw new ApiProblem(422, 'VALIDATION_ERROR', 'Parâmetro de consulta não permitido.');
        }
        $limit = $request->query('limit', '50');
        if ((! is_int($limit) && ! is_string($limit)) || ! preg_match('/^[1-9][0-9]{0,2}$/D', (string) $limit) || (int) $limit > 100) {
            throw new ApiProblem(422, 'VALIDATION_ERROR', 'limit deve estar entre 1 e 100.');
        }
        $limit = (int) $limit;
        $query = DB::table($table)->where('payment_id', $payment->id);
        $cursor = $request->query('cursor');
        if ($cursor !== null) {
            try {
                if (! is_string($cursor) || strlen($cursor) > 1024) {
                    throw new \RuntimeException;
                }
                $position = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
                if (($position['payment_id'] ?? null) !== $payment->id || ($position['kind'] ?? null) !== $table
                    || ! is_string($position['at'] ?? null) || strtotime($position['at']) === false
                    || ! Str::isUuid($position['id'] ?? '')) {
                    throw new \RuntimeException;
                }
            } catch (Throwable) {
                throw new ApiProblem(422, 'VALIDATION_ERROR', 'Cursor inválido.');
            }
            $query->where(function ($q) use ($timestamp, $position) {
                $q->where($timestamp, '>', $position['at'])->orWhere(function ($tie) use ($timestamp, $position) {
                    $tie->where($timestamp, $position['at'])->where('id', '>', $position['id']);
                });
            });
        }
        $rows = $query->orderBy($timestamp)->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        $next = null;
        if ($hasMore && $rows->isNotEmpty()) {
            $last = $rows->last();
            $next = Crypt::encryptString(json_encode(['payment_id' => $payment->id, 'kind' => $table,
                'at' => $last->{$timestamp}, 'id' => $last->id], JSON_THROW_ON_ERROR));
        }

        return response()->json(['data' => $rows->map(fn ($row) => $table === 'ledger_entries'
            ? Representations::ledger($row) : Representations::event($row))->all(), 'next_cursor' => $next, 'simulation' => true]);
    }

    public function health(Request $request)
    {
        Payload::empty($request);
        try {
            DB::select('SELECT 1');

            return response()->json(['status' => 'ok', 'database' => 'ok', 'simulation' => true]);
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable', 'database' => 'unavailable', 'simulation' => true], 503);
        }
    }
}
