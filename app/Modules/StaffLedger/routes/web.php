<?php

declare(strict_types=1);

use App\Modules\StaffLedger\Http\Middleware\AllowGeolocation;
use App\Modules\StaffLedger\Http\MyCheckInController;
use App\Modules\StaffLedger\Http\PersonCardController;
use Illuminate\Support\Facades\Route;

// Behind module:staff_ledger: 404 while the module is off.
Route::view('/personalliggare', 'staff-ledger::overview')->name('staff-ledger.overview');
Route::view('/personalliggare/lokaler', 'staff-ledger::locations')->name('staff-ledger.locations');
Route::view('/personalliggare/personal', 'staff-ledger::people')->name('staff-ledger.people');
Route::get('/personalliggare/personal/{person}/qr-kort', [PersonCardController::class, 'card'])->whereNumber('person')->name('staff-ledger.people.card');
Route::get('/personalliggare/personal/{person}/foto', [PersonCardController::class, 'photo'])->whereNumber('person')->name('staff-ledger.people.photo');
Route::view('/personalliggare/tidrapporter', 'staff-ledger::proposals')->name('staff-ledger.proposals');
Route::view('/personalliggare/min-incheckning', 'staff-ledger::mine')->middleware(AllowGeolocation::class)->name('staff-ledger.mine');
Route::get('/personalliggare/min-incheckning/qr', [MyCheckInController::class, 'qr'])->name('staff-ledger.mine.qr');
