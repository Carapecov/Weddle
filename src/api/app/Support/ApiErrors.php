<?php

namespace App\Support;

use App\Domain\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiErrors
{
    public static function render(Throwable $e, Request $request)
    {
        if (! $request->is('api/*') && ! $request->is('web/*') && ! $request->is('app') && ! $request->is('health')) {
            return null;
        }

        $correlation = $request->attributes->get('correlation_id') ?? (string) Str::uuid();
        if ($e instanceof ApiProblem) {
            $problem = $e;
        } elseif ($e instanceof ValidationException) {
            $details = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $details[] = ['field' => $field, 'code' => 'INVALID', 'message' => $message];
                }
            }
            $problem = new ApiProblem(422, 'VALIDATION_ERROR', 'Payload inválido.', $details);
        } elseif ($e instanceof QueryException) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            $problem = match (true) {
                str_starts_with($state, '08'), in_array($state, ['40001', '40P01', '55P03', '57P01', '57P02', '57P03'], true) => new ApiProblem(503, 'TEMPORARY_UNAVAILABLE', 'Serviço temporariamente indisponível. Repita com a mesma chave.'),
                $state === '23505' => new ApiProblem(409, 'RESOURCE_CONFLICT', 'Recurso ou operação já registrado.'),
                in_array($state, ['23503', '23514', '23502'], true) => new ApiProblem(422, 'VALIDATION_ERROR', 'Dados incompatíveis com as invariantes do cenário.'),
                default => new ApiProblem(500, 'INTERNAL_ERROR', 'Erro interno. Informe o identificador de correlação.'),
            };
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $problem = new ApiProblem($status, match ($status) {
                404 => 'RESOURCE_NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 419 => 'CSRF_MISMATCH',
                429 => 'RATE_LIMITED', default => 'HTTP_ERROR',
            }, match ($status) {
                404 => 'Recurso não encontrado.', 405 => 'Método não permitido.', 419 => 'Sessão expirada. Atualize a página e tente novamente.',
                429 => 'Limite de requisições atingido.', default => 'Não foi possível atender à requisição.',
            });
        } else {
            $problem = new ApiProblem(500, 'INTERNAL_ERROR', 'Erro interno. Informe o identificador de correlação.');
        }

        $headers = ['X-Correlation-ID' => $correlation, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if (in_array($problem->status, [429, 503], true)) {
            $headers['Retry-After'] = '60';
        }

        return response()->json($problem->body($correlation), $problem->status, $headers);
    }
}
