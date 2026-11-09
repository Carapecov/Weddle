<?php

namespace App\Http\Middleware;

use App\Domain\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class WebContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $isJsonRoute = $request->is('web/*');
        if (! $isJsonRoute && ! $request->is('/', 'app', 'docs')) {
            return $next($request);
        }
        $correlation = (string) Str::uuid();
        $request->attributes->set('correlation_id', $correlation);
        if (($isJsonRoute || $request->is('app')) && (
            ! app()->environment(['local', 'testing']) || ! in_array($request->ip(), ['127.0.0.1', '::1'], true)
        )) {
            throw new ApiProblem(403, 'LOCAL_DEMO_ONLY', 'Esta demonstração está disponível somente no ambiente local.');
        }
        if ($isJsonRoute) {
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
            if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                $origin = $request->header('Origin');
                if (($origin !== null && $origin !== $request->getSchemeAndHttpHost())
                    || $request->header('Sec-Fetch-Site') === 'cross-site') {
                    throw new ApiProblem(403, 'ORIGIN_FORBIDDEN', 'Origem não permitida.');
                }
                if ($request->query() !== []) {
                    throw new ApiProblem(422, 'VALIDATION_ERROR', 'Parâmetro de consulta não permitido.');
                }
            }
        }
        $started = hrtime(true);
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $response->headers->set('X-Correlation-ID', $correlation);
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

            return $response;
        } catch (Throwable $exception) {
            $status = $exception instanceof ApiProblem ? $exception->status : 500;
            throw $exception;
        } finally {
            Log::build(['driver' => 'daily', 'path' => storage_path('logs/weddell.log'), 'days' => 7])
                ->info('demo_web_request', [
                    'correlation_id' => $correlation, 'operation' => $request->route()?->getName() ?? 'unmatched',
                    'method' => $request->method(), 'status' => $status,
                    'duration_ms' => round((hrtime(true) - $started) / 1000000, 3),
                ]);
        }
    }
}
