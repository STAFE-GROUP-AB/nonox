<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserHasCompany;
use App\Migration\Fortnox\Http\FortnoxAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class])->group(function () {
    Route::view('/flytt/fortnox', 'fortnox::page')->name('fortnox.move');
    Route::get('/flytt/fortnox/anslut', [FortnoxAuthController::class, 'redirect'])->name('fortnox.connect');
    Route::get('/flytt/fortnox/klar', [FortnoxAuthController::class, 'callback'])->name('fortnox.callback');
});
