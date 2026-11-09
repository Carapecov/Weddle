<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresTestCase;

final class PostgresConcurrencyTest extends PostgresTestCase
{
    /**
     * Parent row locks create an observable overlap, rather than relying on
     * timing. Both independent PostgreSQL sessions must report waiting for a
     * database lock before the parent releases them to race to completion.
     */
    private function race(array $actor, array $payments, string $operation, bool $sameKey): array
    {
        $this->assertSafeDatabase();
        $this->assertCount(2, $payments);
        $workers = [];
        $key = (string) Str::uuid();
        $ready = false;
        DB::beginTransaction();
        try {
            $documents = array_values(array_unique(array_column($payments, 'fiscal_document_id')));
            DB::table('fiscal_documents')->whereIn('id', $documents)->orderBy('id')->lockForUpdate()->get();
            DB::table('payments')->whereIn('id', array_column($payments, 'id'))->orderBy('id')->lockForUpdate()->get();
            foreach ($payments as $payment) {
                $worker = new Process([PHP_BINARY, '-d', 'extension=pdo_pgsql', base_path('tests/Support/http-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
                ]);
                $worker->setInput(json_encode([
                    'database' => config('database.connections.pgsql'), 'token' => $actor['token'],
                    'uri' => '/api/v1/payments/'.$payment['id'].'/'.$operation,
                    'key' => $sameKey ? $key : (string) Str::uuid(),
                ], JSON_THROW_ON_ERROR));
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }

            $pids = [];
            $deadline = microtime(true) + 15;
            do {
                foreach ($workers as $index => $worker) {
                    $worker->isRunning();
                    foreach (explode("\n", $worker->getOutput()) as $line) {
                        $message = json_decode(trim($line), true);
                        if (is_array($message) && ($message['ready'] ?? false)) {
                            $pids[$index] = (int) $message['pid'];
                        }
                    }
                }
                if (count($pids) === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertCount(2, $pids, 'Both subprocesses must initialize their own PostgreSQL session');
            $this->assertNotSame($pids[0], $pids[1], 'Workers must use independent database sessions');
            $this->assertNotContains((int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, $pids);

            $deadline = microtime(true) + 10;
            $overlapped = false;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->whereIn('pid', array_values($pids))->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    $overlapped = true;
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($overlapped, 'Both workers must actually overlap while waiting on PostgreSQL locks');
            $ready = true;
        } finally {
            DB::rollBack();
            if (! $ready) {
                foreach ($workers as $worker) {
                    if ($worker->isRunning()) {
                        $worker->stop(0.1);
                    }
                }
            }
        }

        $responses = [];
        try {
            foreach ($workers as $worker) {
                $exit = $worker->wait();
                $this->assertSame(0, $exit, 'Concurrency worker failed; private connection configuration is intentionally not included');
                $result = null;
                foreach (explode("\n", $worker->getOutput()) as $line) {
                    $message = json_decode(trim($line), true);
                    if (is_array($message) && isset($message['status'])) {
                        $result = $message;
                    }
                }
                $this->assertNotNull($result, 'Worker must return an HTTP result');
                $responses[] = $result;
            }
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0.1);
                }
            }
        }

        return $responses;
    }

    #[DataProvider('sameAndDifferentKeys')]
    public function test_t06_two_real_sessions_cannot_settle_the_same_payment_twice(bool $sameKey): void
    {
        $actor = $this->merchant();
        $payment = $this->authorized($actor);
        $responses = $this->race($actor, [$payment, $payment], 'settle', $sameKey);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame($sameKey ? [200, 200] : [200, 409], $statuses);
        if ($sameKey) {
            $this->assertEquals($responses[0]['body'], $responses[1]['body']);
        }
        $this->assertSame(1, DB::table('document_settlements')->count());
        $this->assertSame(4, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertSame(1, DB::table('payment_events')->where('payment_id', $payment['id'])->where('event_type', 'SETTLED')->count());
        $this->assertBalanced($payment['id']);
    }

    public static function sameAndDifferentKeys(): iterable
    {
        yield 'different-keys' => [false];
        yield 'same-key-replay' => [true];
    }

    public function test_different_payments_of_one_document_cannot_both_settle_concurrently(): void
    {
        $actor = $this->merchant();
        $document = $this->document($actor);
        $first = $this->authorized($actor, $document);
        $second = $this->authorized($actor, $document);
        $responses = $this->race($actor, [$first, $second], 'settle', false);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, DB::table('document_settlements')->count());
        $this->assertSame(4, DB::table('ledger_entries')->count());
        $this->assertSame(1, DB::table('payments')->where('status', 'SETTLED')->count());
    }

    #[DataProvider('sameAndDifferentKeys')]
    public function test_concurrent_refunds_produce_exactly_one_compensation(bool $sameKey): void
    {
        $actor = $this->merchant();
        $payment = $this->settled($actor);
        $responses = $this->race($actor, [$payment, $payment], 'refund', $sameKey);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame($sameKey ? [200, 200] : [200, 409], $statuses);
        $this->assertSame(8, $this->paymentCount('ledger_entries', $payment['id']));
        $this->assertSame(1, DB::table('payment_events')->where('payment_id', $payment['id'])->where('event_type', 'REFUNDED')->count());
        $this->assertSame(1, DB::table('document_settlements')->count());
        $this->assertBalanced($payment['id']);
    }
}
