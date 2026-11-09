<?php

namespace App\Http\Middleware;

use App\Domain\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class DemoAuth
{
    public function handle(Request $request, Closure $next, string $role)
    {
        $failureKey = 'demo-auth:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($failureKey, 30)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Limite de autenticação atingido.');
        }
        $token = $request->bearerToken();
        $credential = null;
        if (is_string($token) && strlen($token) >= 32 && strlen($token) <= 256) {
            $credential = DB::table('demo_credentials')
                ->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        }
        if (! $credential) {
            RateLimiter::hit($failureKey, 60);
            throw new ApiProblem(401, 'UNAUTHENTICATED', 'Credencial de demonstração inválida ou ausente.');
        }
        $key = 'demo-api:'.$credential->token_hash;
        if (RateLimiter::tooManyAttempts($key, 60)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Limite de requisições atingido.');
        }
        RateLimiter::hit($key, 60);
        if ($credential->role !== $role) {
            throw new ApiProblem(403, 'FORBIDDEN', 'Credencial sem permissão para esta operação.');
        }
        $request->attributes->set('demo_credential', $credential);
        $request->attributes->set('merchant_id', $credential->merchant_id);

        return $next($request);
    }
}
