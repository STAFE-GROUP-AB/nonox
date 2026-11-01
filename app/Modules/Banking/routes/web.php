<?php

declare(strict_types=1);

use App\Modules\Banking\Http\BankAuthController;
use Illuminate\Support\Facades\Route;

// Behind module:banking: 404 while the module is off.
Route::view('/bank', 'banking::index')->name('banking.index');
Route::get('/bank/klar', [BankAuthController::class, 'callback'])->middleware('throttle:banking-callback')->name('banking.callback');
Route::get('/bank/testbank', [BankAuthController::class, 'fakeBank'])->name('banking.fake-bank');
