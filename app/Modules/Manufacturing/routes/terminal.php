<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Http\Middleware\AuthenticateWorkshopDevice;
use App\Modules\Manufacturing\Http\Middleware\AuthenticateWorkshopPerson;
use App\Modules\Manufacturing\Http\WorkshopTerminalController;
use Illuminate\Support\Facades\Route;

// No session and no login: the page is the same for every tablet, and the
// endpoints are authenticated by the device token and, after a code, the
// worker's session token (ADR-071 §7). Throttled. Not behind the module
// middleware: a company with the module off answers 404 from the device
// middleware, and the page itself holds no data.
Route::get('/verkstad/terminal', [WorkshopTerminalController::class, 'shell'])->name('manufacturing.terminal');
Route::get('/verkstad/terminal/manifest', [WorkshopTerminalController::class, 'manifest'])->name('manufacturing.terminal.manifest');
Route::get('/verkstad/terminal/ikon', [WorkshopTerminalController::class, 'icon'])->name('manufacturing.terminal.icon');
Route::get('/verkstad/terminal/serviceworker', [WorkshopTerminalController::class, 'worker'])->name('manufacturing.terminal.worker');
Route::post('/verkstad/terminal/koppla', [WorkshopTerminalController::class, 'pair'])->middleware('throttle:workshop-pairing')->name('manufacturing.terminal.pair');

Route::middleware(AuthenticateWorkshopDevice::class)->group(function () {
    Route::get('/verkstad/terminal/personer', [WorkshopTerminalController::class, 'people'])->name('manufacturing.terminal.people');
    Route::post('/verkstad/terminal/inloggning', [WorkshopTerminalController::class, 'signIn'])->middleware('throttle:workshop-code')->name('manufacturing.terminal.sign-in');

    Route::middleware(AuthenticateWorkshopPerson::class)->group(function () {
        Route::get('/verkstad/terminal/ordrar', [WorkshopTerminalController::class, 'orders'])->name('manufacturing.terminal.orders');
        Route::post('/verkstad/terminal/tid', [WorkshopTerminalController::class, 'report'])->name('manufacturing.terminal.report');
        Route::post('/verkstad/terminal/ratta', [WorkshopTerminalController::class, 'correct'])->name('manufacturing.terminal.correct');
        Route::post('/verkstad/terminal/vantar', [WorkshopTerminalController::class, 'wait'])->name('manufacturing.terminal.wait');
        Route::post('/verkstad/terminal/vantar/avsluta', [WorkshopTerminalController::class, 'clearWait'])->name('manufacturing.terminal.clear-wait');
    });
});
