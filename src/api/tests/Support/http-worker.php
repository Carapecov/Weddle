<?php

// Subprocess worker for real PostgreSQL concurrency tests. Private connection
// configuration and demo tokens arrive on stdin, never through command arguments.
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    config(['database.default' => 'pgsql', 'database.connections.pgsql' => $job['database'],
        'cache.default' => 'array', 'weddell.failure_mode' => 'none', 'weddell.fail_at' => null]);
    DB::purge('pgsql');
    if (! $app->environment('testing') || config('database.connections.pgsql.host') !== '127.0.0.1'
        || (int) config('database.connections.pgsql.port') !== 55432
        || config('database.connections.pgsql.driver') !== 'pgsql'
        || config('database.connections.pgsql.database') !== 'weddell_pay_test'
        || DB::selectOne('SELECT current_database() AS name')->name !== 'weddell_pay_test') {
        throw new RuntimeException('Unsafe concurrency database');
    }
    $pid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    echo json_encode(['ready' => true, 'pid' => $pid], JSON_THROW_ON_ERROR).PHP_EOL;
    flush();

    $request = Request::create($job['uri'], 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$job['token'], 'HTTP_IDEMPOTENCY_KEY' => $job['key'],
    ], '{}');
    $response = $kernel->handle($request);
    echo json_encode(['status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
    $kernel->terminate($request, $response);
} catch (Throwable $exception) {
    // Do not disclose private configuration, tokens or SQL/connection exceptions.
    fwrite(STDERR, 'Concurrency worker failed: '.get_class($exception).PHP_EOL);
    exit(1);
}
