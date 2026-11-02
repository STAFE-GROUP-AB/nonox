<?php

declare(strict_types=1);

use App\Modules\Pos\Http\PosAuthController;
use Illuminate\Support\Facades\Route;

// Behind module:pos: 404 while the module is off.
Route::view('/anslutningar/kassa', 'pos::index')->name('pos.index');
// The till's answer after approval; the address registered with Zettle and Onslip.
Route::get('/anslutningar/kassa/{provider}/klar', PosAuthController::class)
    ->whereIn('provider', ['zettle', 'onslip'])
    ->middleware('throttle:pos-callback')
    ->name('pos.callback');
