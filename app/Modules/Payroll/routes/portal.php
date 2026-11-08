<?php

declare(strict_types=1);

use App\Modules\Payroll\Http\PayslipController;
use Illuminate\Support\Facades\Route;

Route::view('/anstalld', 'payroll::portal.home')->name('payroll.portal.home');
Route::view('/anstalld/korjournal', 'payroll::portal.mileage')->name('payroll.portal.mileage');
// The staff app's shortcuts (anstalld.webmanifest, ADR-072 §8): straight to the receipt photo and to a new trip.
Route::redirect('/anstalld/kvitto', '/anstalld?utlagg=1')->name('payroll.portal.receipt');
Route::redirect('/anstalld/korjournal/ny', '/anstalld/korjournal?ny=1')->name('payroll.portal.mileage.new');
Route::get('/anstalld/lonebesked/{payslip}', PayslipController::class)->whereNumber('payslip')->name('payroll.portal.payslip');
