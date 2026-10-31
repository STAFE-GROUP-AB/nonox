<?php

declare(strict_types=1);

use App\Modules\StaffLedger\Http\KioskController;
use App\Modules\StaffLedger\Http\Middleware\AuthenticateKioskDevice;
use Illuminate\Support\Facades\Route;

// No session and no login: the page is the same for every kiosk, and the
// endpoints are authenticated by the device token (ADR-022). Throttled.
Route::get('/personalliggare/kiosk/start', [KioskController::class, 'shell'])->name('staff-ledger.kiosk');
Route::get('/personalliggare/kiosk/manifest', [KioskController::class, 'manifest'])->name('staff-ledger.kiosk.manifest');
Route::get('/personalliggare/kiosk/ikon', [KioskController::class, 'icon'])->name('staff-ledger.kiosk.icon');
Route::get('/personalliggare/kiosk/serviceworker', [KioskController::class, 'worker'])->name('staff-ledger.kiosk.worker');
Route::post('/personalliggare/kiosk/koppla', [KioskController::class, 'pair'])->middleware('throttle:staff-ledger-pairing')->name('staff-ledger.kiosk.pair');

Route::middleware(AuthenticateKioskDevice::class)->group(function () {
    Route::get('/personalliggare/kiosk/data', [KioskController::class, 'roster'])->name('staff-ledger.kiosk.roster');
    Route::post('/personalliggare/kiosk/handelser', [KioskController::class, 'events'])->name('staff-ledger.kiosk.events');
    Route::post('/personalliggare/kiosk/kontrollbesok', [KioskController::class, 'controlVisit'])->name('staff-ledger.kiosk.control-visit');
});
