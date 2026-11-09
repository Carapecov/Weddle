<?php

namespace App\Services;

use App\Domain\ApiProblem;
use App\Support\Representations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WebDemoService
{
    public function __construct(
        private PaymentService $payments,
        private IdempotencyService $idempotency,
    ) {}

    /** @return array{merchant_id: string, credential_id: string} */
    public function provision(): array
    {
        return DB::transaction(function (): array {
            $merchant = (string) Str::uuid();
            $credential = (string) Str::uuid();
            $suffix = strtoupper(Str::random(12));
            DB::table('merchants')->insert([
                'id' => $merchant, 'name' => 'DEMO-WEB-'.$suffix,
                'virtual_account_id' => 'DEMO-ACCOUNT-WEB-'.$suffix, 'created_at' => now('UTC'),
            ]);
            DB::table('demo_credentials')->insert([
                'id' => $credential, 'merchant_id' => $merchant, 'role' => 'merchant',
                'token_hash' => hash('sha256', random_bytes(32)),
                'revoked_at' => null, 'created_at' => now('UTC'),
            ]);
            $this->seedExamples($merchant);

            return ['merchant_id' => $merchant, 'credential_id' => $credential];
        });
    }

    /** @return array<string, mixed> */
    public function create(string $merchant, array $command, string $key): array
    {
        return $this->createPayment($merchant, $command, $key, 'web-create');
    }

    /** @return array<string, mixed> */
    private function createPayment(string $merchant, array $command, string $key, string $namespace): array
    {
        $scopedKey = $namespace.':'.hash('sha256', $key);

        return $this->idempotency->run($merchant, 'create_payment', 'create', $scopedKey,
            ['source' => $namespace, ...$command], function () use ($merchant, $command, $scopedKey): array {
                $document = (string) Str::uuid();
                $reference = 'DEMO-DOC-WEB-'.strtoupper(substr(hash('sha256', $merchant.'/'.$scopedKey), 0, 32));
                DB::table('fiscal_documents')->insert([
                    'id' => $document, 'merchant_id' => $merchant, 'reference' => $reference,
                    'gross_amount_cents' => $command['gross_amount_cents'],
                    'cbs_amount_cents' => $command['cbs_amount_cents'], 'ibs_amount_cents' => $command['ibs_amount_cents'],
                    'status' => 'ISSUED', 'created_at' => now('UTC'),
                ]);
                if (app()->environment('testing') && config('weddell.fail_at') === 'web_after_document') {
                    throw new ApiProblem(503, 'TEMPORARY_UNAVAILABLE', 'Falha simulada antes de criar o pagamento.');
                }
                $result = $this->payments->create($merchant, [
                    'fiscal_document_id' => $document, 'amount_cents' => $command['gross_amount_cents'],
                    'currency' => 'BRL', 'scenario' => $command['scenario'],
                ]);
                $result['headers']['Location'] = route('web.payments.show', ['id' => $result['body']['id']], false);

                return $result;
            });
    }

    /** @return array{seeded: bool, created_count: int} */
    public function seedExamples(string $merchant): array
    {
        return DB::transaction(function () use ($merchant): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['web-examples:'.$merchant]);
            $before = DB::table('payments')->where('merchant_id', $merchant)->count();
            $examples = [
                ['CREATED', 284900, 22792, 34188],
                ['AUTHORIZED', 156000, 12480, 18720],
                ['SETTLED', 100000, 8000, 12000],
                ['SETTLED', 46900, 3752, 5628],
                ['FAILED', 89900, 7192, 10788],
                ['REFUNDED', 250000, 20000, 30000],
            ];
            foreach ($examples as $index => [$target, $gross, $cbs, $ibs]) {
                $key = 'sample-'.($index + 1);
                $result = $this->createPayment($merchant, [
                    'gross_amount_cents' => $gross, 'cbs_amount_cents' => $cbs, 'ibs_amount_cents' => $ibs,
                    'scenario' => $target === 'FAILED' ? 'declined' : 'approved',
                ], $key, 'web-example');
                if ($result['status'] !== 201) {
                    throw new ApiProblem(503, 'TEMPORARY_UNAVAILABLE', 'Não foi possível preparar os exemplos.');
                }
                $id = $result['body']['id'];
                $operations = $target === 'CREATED' ? [] : ['authorize_payment'];
                if (in_array($target, ['SETTLED', 'REFUNDED'], true)) {
                    $operations[] = 'settle_payment';
                }
                if ($target === 'REFUNDED') {
                    $operations[] = 'refund_payment';
                }
                foreach ($operations as $operation) {
                    $transition = $this->idempotency->run(
                        $merchant, $operation, $id, 'web-example:'.hash('sha256', $key.'/'.$operation), [],
                        fn (): array => $this->payments->transition($merchant, $id, $operation),
                    );
                    if ($transition['status'] !== 200) {
                        throw new ApiProblem(503, 'TEMPORARY_UNAVAILABLE', 'Não foi possível preparar os exemplos.');
                    }
                }
            }
            $after = DB::table('payments')->where('merchant_id', $merchant)->count();

            return ['seeded' => true, 'created_count' => $after - $before];
        });
    }

    private function exactAggregate(int|string|null $value): int|string
    {
        $digits = ltrim((string) ($value ?? 0), '0');
        $digits = $digits === '' ? '0' : $digits;
        $maximum = '9007199254740991';
        if (strlen($digits) < strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) <= 0)) {
            return (int) $digits;
        }

        return $digits;
    }

    private function query(string $merchant): Builder
    {
        return DB::table('payments as p')->join('fiscal_documents as d', 'd.id', '=', 'p.fiscal_document_id')
            ->where('p.merchant_id', $merchant);
    }

    /** @return array<string, mixed> */
    public function overview(string $merchant): array
    {
        $row = $this->query($merchant)->selectRaw("
            COUNT(*) AS total_payments, COALESCE(SUM(p.amount_cents),0) AS total_amount_cents,
            COALESCE(SUM(p.amount_cents) FILTER (WHERE p.status='SETTLED'),0) AS settled_amount_cents,
            COUNT(*) FILTER (WHERE p.status='AUTHORIZED') AS authorized_count,
            COUNT(*) FILTER (WHERE p.status='FAILED') AS failed_count,
            COUNT(*) FILTER (WHERE p.status='REFUNDED') AS refunded_count,
            COUNT(*) FILTER (WHERE p.status='SETTLED') AS settled_count,
            COALESCE(SUM(d.cbs_amount_cents) FILTER (WHERE p.status='SETTLED'),0) AS cbs_amount_cents,
            COALESCE(SUM(d.ibs_amount_cents) FILTER (WHERE p.status='SETTLED'),0) AS ibs_amount_cents,
            COALESCE(SUM(p.amount_cents-d.cbs_amount_cents-d.ibs_amount_cents) FILTER (WHERE p.status='SETTLED'),0) AS merchant_net_cents
        ")->first();
        $summary = array_map(fn ($value): int|string => $this->exactAggregate($value), (array) $row);
        $start = CarbonImmutable::today('America/Sao_Paulo')->subDays(6);
        $days = $this->query($merchant)->where('p.created_at', '>=', $start->utc()->toIso8601String())
            ->selectRaw("to_char(p.created_at AT TIME ZONE 'America/Sao_Paulo','YYYY-MM-DD') AS date, SUM(p.amount_cents) AS amount_cents, COUNT(*) AS count")
            ->groupByRaw("to_char(p.created_at AT TIME ZONE 'America/Sao_Paulo','YYYY-MM-DD')")->get()->keyBy('date');
        $daily = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $date = $start->addDays($offset)->toDateString();
            $actual = $days->get($date);
            $daily[] = ['date' => $date, 'amount_cents' => $this->exactAggregate($actual->amount_cents ?? 0), 'count' => $this->exactAggregate($actual->count ?? 0)];
        }
        $recent = $this->query($merchant)->select('p.*')->orderByDesc('p.created_at')->orderByDesc('p.id')->limit(6)->get();

        return ['summary' => $summary, 'daily' => $daily,
            'recent' => $recent->map(static fn (object $payment): array => Representations::payment($payment))->all()];
    }

    /** @return array<string, mixed> */
    public function listing(string $merchant, string $status, string $search, int $page, int $limit): array
    {
        $query = $this->query($merchant);
        if ($status !== 'ALL') {
            $query->where('p.status', $status);
        }
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->whereRaw('POSITION(LOWER(?) IN LOWER(CAST(p.id AS TEXT))) > 0', [$search])
                    ->orWhereRaw('POSITION(LOWER(?) IN LOWER(d.reference)) > 0', [$search]);
            });
        }
        $total = (clone $query)->count('p.id');
        $payments = $query->select('p.*')->orderByDesc('p.created_at')->orderByDesc('p.id')
            ->offset(($page - 1) * $limit)->limit($limit)->get();

        return ['data' => $payments->map(static fn (object $payment): array => Representations::payment($payment))->all(),
            'meta' => ['current_page' => $page, 'last_page' => $this->exactAggregate(max(1, intdiv($total, $limit) + ($total % $limit > 0 ? 1 : 0))),
                'total' => $this->exactAggregate($total), 'per_page' => $limit]];
    }

    /** @return array<string, mixed> */
    public function detail(string $merchant, string $id): array
    {
        $payment = $this->payments->ownedPayment($id, $merchant);
        $document = $this->payments->ownedDocument($payment->fiscal_document_id, $merchant);
        $ledger = DB::table('ledger_entries')->where('payment_id', $payment->id)
            ->orderBy('created_at')->orderBy('id')->limit(100)->get();
        $events = DB::table('payment_events')->where('payment_id', $payment->id)
            ->orderBy('occurred_at')->orderBy('id')->limit(100)->get();

        return ['payment' => Representations::payment($payment), 'document' => Representations::document($document),
            'ledger' => $ledger->map(static fn (object $row): array => Representations::ledger($row))->all(),
            'events' => $events->map(static fn (object $row): array => Representations::event($row))->all()];
    }
}
