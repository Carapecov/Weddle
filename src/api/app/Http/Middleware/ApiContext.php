<?php

namespace App\Http\Middleware;

use App\Domain\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class ApiContext
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('api/*') && ! $request->is('health')) {
            return $next($request);
        }
        $correlation = (string) Str::uuid();
        $request->attributes->set('correlation_id', $correlation);
        $raw = $request->getContent();
        if (strlen($raw) > 16384 || (int) $request->header('Content-Length', 0) > 16384) {
            throw new ApiProblem(413, 'PAYLOAD_TOO_LARGE', 'Corpo excede 16 KiB.');
        }
        if ($raw !== '') {
            $media = strtolower(trim(explode(';', $request->header('Content-Type', ''))[0]));
            if ($media !== 'application/json') {
                throw new ApiProblem(415, 'UNSUPPORTED_MEDIA_TYPE', 'Use application/json.');
            }
            try {
                $decoded = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ApiProblem(400, 'BAD_REQUEST', 'JSON malformado.');
            }
            if (! is_object($decoded)) {
                throw new ApiProblem(400, 'BAD_REQUEST', 'O corpo deve ser um objeto JSON.');
            }
        }
        $started = hrtime(true);
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $response->headers->set('X-Correlation-ID', $correlation);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (Throwable $e) {
            $status = $e instanceof ApiProblem ? $e->status : 500;
            throw $e;
        } finally {
            Log::build(['driver' => 'daily', 'path' => storage_path('logs/weddell.log'), 'days' => 7])
                ->info('demo_api_request', [
                    'correlation_id' => $correlation, 'operation' => $request->route()?->getName() ?? 'unmatched',
                    'method' => $request->method(), 'status' => $status,
                    'duration_ms' => round((hrtime(true) - $started) / 1000000, 3),
                ]);
        }
    }
}
