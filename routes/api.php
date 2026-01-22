<?php

use App\Http\Controllers\WooOrderWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/webhooks/woocommerce/order-created', [WooOrderWebhookController::class, 'handle']);
Route::post('/woocommerce/order_created', [WooOrderWebhookController::class, 'handle']);