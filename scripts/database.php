<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/api/vendor/autoload.php';
use Symfony\Component\Process\Process;
$root = dirname(__DIR__);
$secrets = parse_ini_file($root.'/.env', false, INI_SCANNER_RAW);
try {
    $connect = static fn (string $database): PDO => new PDO(
        "pgsql:host=127.0.0.1;port=55432;dbname=".$database,
        'weddell_owner', $secrets['WEDDELL_DB_OWNER_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db = $connect('postgres');
    $exists = $db->query("SELECT 1 FROM pg_roles WHERE rolname='weddell_app'")->fetchColumn();
    $password = $db->quote($secrets['WEDDELL_DB_APP_PASSWORD']);
    $db->exec(($exists ? 'ALTER ROLE' : 'CREATE ROLE')." weddell_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD ".$password);
    foreach (['weddell_pay', 'weddell_pay_test'] as $database) {
        $query = $db->prepare('SELECT 1 FROM pg_database WHERE datname=?');
        $query->execute([$database]);
        if (!$query->fetchColumn()) $db->exec('CREATE DATABASE "'.$database.'" OWNER weddell_owner');
        $target = $connect($database);
        $target->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
        $target->beginTransaction();
        if ((int)$target->query('SELECT 1')->fetchColumn() !== 1) throw new RuntimeException('Connection check failed');
        $target->rollBack();
        echo $database.": PostgreSQL connection and transaction verified.\n";
    }
    if (in_array('--prepare-only', $argv, true)) exit(0);
    foreach (['weddell_pay','weddell_pay_test'] as $database) {
        $env = ['DB_DATABASE'=>$database, 'DB_USERNAME'=>'weddell_owner', 'DB_PASSWORD'=>$secrets['WEDDELL_DB_OWNER_PASSWORD']];
        $process = new Process([PHP_BINARY,'-d','extension=pdo_pgsql',$root.'/src/api/artisan','migrate','--force','--no-interaction'], $root.'/src/api', $env);
        $process->setTimeout(120);
        $process->mustRun(static function ($type,$buffer) { echo $buffer; });
        $target = $connect($database);
        $target->exec('GRANT CONNECT ON DATABASE "'.$database.'" TO weddell_app');
        $target->exec('GRANT USAGE ON SCHEMA public TO weddell_app');
        $target->exec('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM weddell_app');
        $target->exec('GRANT SELECT, INSERT ON ALL TABLES IN SCHEMA public TO weddell_app');
        $target->exec('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO weddell_app');
        foreach (['ledger_entries','payment_events','document_settlements'] as $immutable) {
            $check=$target->prepare("SELECT to_regclass(?)");
            $check->execute(['public.'.$immutable]);
            if ($check->fetchColumn()) $target->exec('REVOKE UPDATE, DELETE, TRUNCATE ON "'.$immutable.'" FROM weddell_app');
        }
        $target->exec('REVOKE DELETE, TRUNCATE ON ALL TABLES IN SCHEMA public FROM weddell_app');
        $target->exec('GRANT UPDATE ON payments, idempotency_records TO weddell_app');
        $target->exec('GRANT UPDATE (revoked_at) ON demo_credentials TO weddell_app');
        $target->exec('GRANT UPDATE (id) ON fiscal_documents TO weddell_app');
        $target->exec('REVOKE ALL ON migrations FROM weddell_app');
    }
    echo "Migrations applied and local runtime permissions granted. No demo tokens printed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Database setup failed ('.get_class($error).', code '.$error->getCode()."). No credentials were logged.\n");
    exit(1);
}
