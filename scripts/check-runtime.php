<?php
declare(strict_types=1);
$secrets=parse_ini_file(dirname(__DIR__).'/.env',false,INI_SCANNER_RAW);
try {
    $db=new PDO('pgsql:host=127.0.0.1;port=55432;dbname=weddell_pay','weddell_app',$secrets['WEDDELL_DB_APP_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $checks=[];
    foreach (['ledger_entries','payment_events','document_settlements'] as $table) {
        foreach (['UPDATE','DELETE','TRUNCATE'] as $permission) {
            $query=$db->prepare('SELECT has_table_privilege(current_user,?,?)');
            $query->execute([$table,$permission]);
            if ($query->fetchColumn()) throw new RuntimeException('Unexpected historical privilege.');
            $checks[$table.'.'.$permission]=false;
        }
        foreach (['UPDATE '.$table.' SET id=id WHERE false','DELETE FROM '.$table.' WHERE false'] as $sql) {
            if ($table==='document_settlements' && str_starts_with($sql,'UPDATE')) $sql='UPDATE document_settlements SET payment_id=payment_id WHERE false';
            try { $db->exec($sql); throw new RuntimeException('Historical write unexpectedly allowed.'); }
            catch (PDOException $error) {
                if ($error->getCode()!=='42501') throw new RuntimeException('Unexpected denial code.');
            }
        }
    }
    $checks['superuser']=(bool)$db->query("SELECT rolsuper FROM pg_roles WHERE rolname=current_user")->fetchColumn();
    $checks['schema_create']=(bool)$db->query("SELECT has_schema_privilege(current_user,'public','CREATE')")->fetchColumn();
    $checks['document_amount_update']=(bool)$db->query("SELECT has_column_privilege(current_user,'fiscal_documents','gross_amount_cents','UPDATE')")->fetchColumn();
    $checks['credential_hash_update']=(bool)$db->query("SELECT has_column_privilege(current_user,'demo_credentials','token_hash','UPDATE')")->fetchColumn();
    foreach (['superuser','schema_create','document_amount_update','credential_hash_update'] as $name) if ($checks[$name]) throw new RuntimeException('Unexpected runtime authority.');
    foreach (['document_row_lock'=>['fiscal_documents','id'],'key_revocation'=>['demo_credentials','revoked_at']] as $name=>[$table,$column]) {
        $query=$db->prepare("SELECT has_column_privilege(current_user,?,?,'UPDATE')");
        $query->execute([$table,$column]);
        $checks[$name]=(bool)$query->fetchColumn();
        if (!$checks[$name]) throw new RuntimeException('Missing required nominal privilege.');
    }
    echo json_encode(['result'=>'passed','database_role'=>'weddell_app','safe_zero_row_write_attempts_denied'=>6,'checks'=>$checks],JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,json_encode(['result'=>'failed','message'=>$error->getMessage()]).PHP_EOL);
    exit(1);
}
