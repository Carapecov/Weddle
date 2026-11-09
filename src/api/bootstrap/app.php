<?php

use App\Domain\ApiProblem;
use App\Http\Middleware\ApiContext;
use App\Http\Middleware\DemoAuth;
use App\Http\Middleware\WebContext;
use App\Http\Middleware\WebDemoSession;
use App\Support\ApiErrors;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ApiContext::class);
        $middleware->prepend(WebContext::class);
        $middleware->alias(['demo.auth' => DemoAuth::class, 'web.demo' => WebDemoSession::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('web/*') || $request->is('health') || $request->expectsJson(),
        );
        $exceptions->render(fn (Throwable $e, Request $request) => ApiErrors::render($e, $request));
        // Do not report raw request/SQL/token data; structured operational logs are added explicitly.
        $exceptions->dontReport([ApiProblem::class, QueryException::class]);
    })->create();
