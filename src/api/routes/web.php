<?php

use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\WebDemoController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [PaymentController::class, 'health'])->name('health');
Route::get('/', [WebDemoController::class, 'landing'])->name('web.landing');
Route::get('/docs', [WebDemoController::class, 'docs'])->name('web.docs');
Route::get('/app', [WebDemoController::class, 'app'])->middleware('web.demo')->name('web.app');

Route::prefix('web')->name('web.')->group(function (): void {
    Route::post('session', [WebDemoController::class, 'start'])->name('session')->block(10, 10);
    Route::middleware('web.demo')->group(function (): void {
        Route::post('logout', [WebDemoController::class, 'logout'])->name('logout')->block(10, 10);
        Route::get('overview', [WebDemoController::class, 'overview'])->name('overview');
        Route::get('payments', [WebDemoController::class, 'listing'])->name('payments.index');
        Route::get('payments/{id}', [WebDemoController::class, 'show'])->whereUuid('id')->name('payments.show');
        Route::post('payments', [WebDemoController::class, 'create'])->name('payments.create');
        Route::post('payments/{id}/authorize', [WebDemoController::class, 'authorize'])->whereUuid('id')->name('payments.authorize');
        Route::post('payments/{id}/settle', [WebDemoController::class, 'settle'])->whereUuid('id')->name('payments.settle');
        Route::post('payments/{id}/refund', [WebDemoController::class, 'refund'])->whereUuid('id')->name('payments.refund');
        Route::post('examples', [WebDemoController::class, 'examples'])->name('examples')->block(10, 10);
    });
});

Route::get('/docs/openapi', [WebDemoController::class, 'openApi'])->name('web.docs.openapi');
Route::get('/docs/openapi-web', [WebDemoController::class, 'webOpenApi'])->name('web.docs.openapi-web');
