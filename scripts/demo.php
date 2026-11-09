<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/api/vendor/autoload.php';
use Symfony\Component\Process\Process;
$root = dirname(__DIR__);
$credentials = [];
$requests = [];
function canonicalValue(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value);
    foreach ($value as $key => $item) $value[$key] = canonicalValue($item);
    return $value;
}
function cli(array $arguments): array {
    global $root;
    $process = new Process([PHP_BINARY,'-d','extension=pdo_pgsql',$root.'/src/api/artisan',...$arguments], $root.'/src/api');
    $process->setTimeout(30);
    $process->mustRun();
    return json_decode(trim($process->getOutput()), true, 32, JSON_THROW_ON_ERROR);
}
function httpCall(string $method, string $path, ?string $token = null, ?array $body = null, ?string $key = null, int $expected = 200): array {
    global $requests;
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer '.$token;
    if ($key !== null) $headers[] = 'Idempotency-Key: '.$key;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $curl = curl_init('http://127.0.0.1:8000'.$path);
    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
    if ($body !== null) curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    if ($raw === false) throw new RuntimeException('Local HTTP connection failed.');
    $decoded = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    $requests[] = ['method'=>$method,'path'=>$path,'status'=>$status];
    if ($status !== $expected) throw new RuntimeException('Unexpected HTTP status '.$status.' for '.$method.' '.$path.'; expected '.$expected.'.');
    return $decoded;
}
$resultCode=0;
try {
    $health=httpCall('GET','/health');
    if (($health['simulation']??null)!==true || ($health['database']??null)!=='ok') throw new RuntimeException('Health mismatch.');
    $operator=cli(['demo:operator','--json']);
    $credentials[]=$operator['id'];
    $suffix=strtoupper(bin2hex(random_bytes(5)));
    $merchant=httpCall('POST','/api/v1/merchants',$operator['token'],['name'=>'DEMO-HTTP-'.$suffix,'virtual_account_id'=>'DEMO-ACCOUNT-'.$suffix],null,201);
    $issued=cli(['demo:merchant-key',$merchant['id'],'--json']);
    $credentials[]=$issued['id'];
    $token=$issued['token'];
    $doc=httpCall('POST','/api/v1/fiscal-documents',$token,['merchant_id'=>$merchant['id'],'reference'=>'DEMO-DOC-'.$suffix,'gross_amount_cents'=>100000,'cbs_amount_cents'=>8000,'ibs_amount_cents'=>12000],null,201);
    $payment=httpCall('POST','/api/v1/payments',$token,['merchant_id'=>$merchant['id'],'fiscal_document_id'=>$doc['id'],'amount_cents'=>100000,'currency'=>'BRL','scenario'=>'approved'],'demo-create-'.$suffix,201);
    if ($payment['status']!=='CREATED' || $payment['split']!==null) throw new RuntimeException('Creation mismatch.');
    $id=$payment['id'];
    $authorized=httpCall('POST','/api/v1/payments/'.$id.'/authorize',$token,null,'demo-auth-'.$suffix);
    if ($authorized['status']!=='AUTHORIZED') throw new RuntimeException('Authorization mismatch.');
    $settled=httpCall('POST','/api/v1/payments/'.$id.'/settle',$token,null,'demo-settle-'.$suffix);
    $expectedSplit=['merchant_net_cents'=>80000,'cbs_cents'=>8000,'ibs_cents'=>12000];
    if ($settled['status']!=='SETTLED' || $settled['split']!==$expectedSplit) throw new RuntimeException('Settlement amounts mismatch.');
    $ledger=httpCall('GET','/api/v1/payments/'.$id.'/ledger',$token);
    if (count($ledger['data'])!==4) throw new RuntimeException('Settlement ledger mismatch.');
    $refunded=httpCall('POST','/api/v1/payments/'.$id.'/refund',$token,null,'demo-refund-'.$suffix);
    if ($refunded['status']!=='REFUNDED') throw new RuntimeException('Refund mismatch.');
    $replay=httpCall('POST','/api/v1/payments/'.$id.'/settle',$token,null,'demo-settle-'.$suffix);
    if (canonicalValue($replay)!==canonicalValue($settled)) throw new RuntimeException('Replay did not preserve original response.');
    $current=httpCall('GET','/api/v1/payments/'.$id,$token);
    if ($current['status']!=='REFUNDED') throw new RuntimeException('Current state mismatch.');
    $history=httpCall('GET','/api/v1/payments/'.$id.'/events',$token);
    $ledger=httpCall('GET','/api/v1/payments/'.$id.'/ledger',$token);
    if (count($ledger['data'])!==8 || count($history['data'])!==4) throw new RuntimeException('History mismatch.');
    $balances=[];
    foreach ($ledger['data'] as $entry) {
        $account=$entry['account_code'];
        $balances[$account]=($balances[$account]??0)+($entry['direction']==='DEBIT'?1:-1)*$entry['amount_cents'];
    }
    foreach ($balances as $balance) if ($balance!==0) throw new RuntimeException('Refund does not compensate ledger.');
    $revoked=cli(['demo:revoke-key',$issued['id'],'--json']);
    if (($revoked['revoked']??false)!==true) throw new RuntimeException('Credential revocation failed.');
    httpCall('GET','/api/v1/payments/'.$id,$token,null,null,401);
    echo json_encode(['result'=>'passed','transport'=>'real HTTP loopback','database_role'=>'weddell_app','simulation'=>true,
        'payment_id'=>$id,'final_status'=>$current['status'],'gross_amount_cents'=>100000,'split'=>$settled['split'],
        'ledger_entries'=>8,'audit_events'=>4,'refund_account_balances'=>$balances,'revocation_verified'=>true,
        'requests'=>$requests],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,json_encode(['result'=>'failed','message'=>$error->getMessage(),'requests'=>$requests],JSON_UNESCAPED_SLASHES).PHP_EOL);
    $resultCode=1;
} finally {
    foreach ($credentials as $credential) {
        try { cli(['demo:revoke-key',$credential,'--json']); } catch (Throwable) {
            $resultCode=1;
            fwrite(STDERR,"Temporary demo credential cleanup failed; revoke its ID locally.\n");
        }
    }
}

exit($resultCode);
