<?php

return [
    // Somente APP_ENV=testing pode ativar estes pontos de falha por configuração em memória.
    // Nunca receber estes valores de payload, cabeçalho ou query string.
    'failure_mode' => 'none',
    'fail_at' => null,
];
