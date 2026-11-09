<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$rootEnv = $root.'/.env';
if (!file_exists($rootEnv)) {
    file_put_contents($rootEnv, 'WEDDELL_DB_OWNER_PASSWORD='.bin2hex(random_bytes(24)).PHP_EOL.'WEDDELL_DB_APP_PASSWORD='.bin2hex(random_bytes(24)).PHP_EOL);
}
$secrets = parse_ini_file($rootEnv, false, INI_SCANNER_RAW);
if (empty($secrets['WEDDELL_DB_OWNER_PASSWORD']) || empty($secrets['WEDDELL_DB_APP_PASSWORD'])) {
    fwrite(STDERR, "Configure the two private database passwords in the root .env.\n"); exit(1);
}
$example = file_get_contents($root.'/src/api/.env.example');
foreach (['.env' => ['weddell_pay','weddell_app',$secrets['WEDDELL_DB_APP_PASSWORD']],
          '.env.testing' => ['weddell_pay_test','weddell_owner',$secrets['WEDDELL_DB_OWNER_PASSWORD']]] as $name => [$db,$user,$password]) {
    $target = $root.'/src/api/'.$name;
    if (file_exists($target)) continue;
    $content = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=base64:'.base64_encode(random_bytes(32)), $example);
    $content = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE='.$db, $content);
    $content = preg_replace('/^DB_USERNAME=.*$/m', 'DB_USERNAME='.$user, $content);
    $content = preg_replace('/^DB_PASSWORD=.*$/m', 'DB_PASSWORD='.$password, $content);
    if ($name === '.env.testing') {
        $content = str_replace(['APP_ENV=local','CACHE_STORE=file'], ['APP_ENV=testing','CACHE_STORE=array'], $content);
    }
    file_put_contents($target, $content);
}
echo "Private local environment configured; credentials were not printed.\n";
