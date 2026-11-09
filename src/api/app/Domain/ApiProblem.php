<?php

namespace App\Domain;

use RuntimeException;

final class ApiProblem extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function body(?string $correlation = null): array
    {
        return ['error' => [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'details' => $this->details,
            'correlation_id' => $correlation ?? request()->attributes->get('correlation_id'),
        ]];
    }
}
