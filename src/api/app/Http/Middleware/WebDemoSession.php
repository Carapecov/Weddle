<?php

namespace App\Http\Middleware;

use App\Domain\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class WebDemoSession
{
    public static function credential(Request $request): ?object
    {
        $merchant = $request->session()->get('web_demo.merchant_id');
        $credential = $request->session()->get('web_demo.credential_id');
        if (! is_string($merchant) || ! is_string($credential) || ! Str::isUuid($merchant) || ! Str::isUuid($credential)) {
            return null;
        }

        return DB::table('demo_credentials')->where('id', $credential)->where('merchant_id', $merchant)
            ->where('role', 'merchant')->whereNull('revoked_at')->first();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $credential = self::credential($request);
        if ($credential === null) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->is('app') && ! $request->expectsJson()) {
                return redirect()->route('web.landing');
            }
            throw new ApiProblem(401, 'SESSION_REQUIRED', 'Abra uma demonstração para continuar.');
        }
        $rateKey = 'web-demo:'.$credential->id;
        if (RateLimiter::tooManyAttempts($rateKey, 60)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Limite de requisições atingido.');
        }
        RateLimiter::hit($rateKey, 60);
        $request->attributes->set('merchant_id', $credential->merchant_id);
        $request->attributes->set('web_credential_id', $credential->id);

        return $next($request);
    }
}
