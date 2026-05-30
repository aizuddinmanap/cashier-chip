<?php

use Aizuddinmanap\CashierChip\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cashier Routes
|--------------------------------------------------------------------------
|
| Here are the routes for the Cashier Chip package. These routes handle
| incoming webhooks from Chip and other related functionality.
|
*/

Route::post('/webhook', [WebhookController::class, 'handleWebhook'])
    ->name('webhook')
    ->middleware('chip.webhook');
