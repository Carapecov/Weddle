<?php

return [
    // Nenhuma origem de navegador foi aprovada; habilitação futura exige revisão explícita.
    'paths' => ['api/*', 'health'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
