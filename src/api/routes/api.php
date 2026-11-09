<?php

use App\Http\Controllers\Api\V1\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('merchants', [PaymentController::class, 'merchant'])->middleware('demo.auth:operator')->name('merchants.create');
    Route::middleware('demo.auth:merchant')->group(function (): void {
        Route::post('fiscal-documents', [PaymentController::class, 'document'])->name('documents.create');
        Route::post('payments', [PaymentController::class, 'create'])->name('payments.create');
        Route::get('payments/{id}', [PaymentController::class, 'show'])->whereUuid('id')->name('payments.show');
        Route::get('payments/{id}/ledger', [PaymentController::class, 'ledger'])->whereUuid('id')->name('payments.ledger');
        Route::get('payments/{id}/events', [PaymentController::class, 'events'])->whereUuid('id')->name('payments.events');
        Route::post('payments/{id}/authorize', [PaymentController::class, 'authorize'])->whereUuid('id')->name('payments.authorize');
        Route::post('payments/{id}/settle', [PaymentController::class, 'settle'])->whereUuid('id')->name('payments.settle');
        Route::post('payments/{id}/refund', [PaymentController::class, 'refund'])->whereUuid('id')->name('payments.refund');
    });
});
