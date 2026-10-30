<?php

declare(strict_types=1);

use App\Modules\Payroll\Http\AgiFileController;
use App\Modules\Payroll\Http\ImportedPayslipController;
use App\Modules\Payroll\Http\PayslipController;
use Illuminate\Support\Facades\Route;

/*
 * Lönearkivet (PAYROLL_MODULE.md A.6): read-only, and reachable while the
 * module is off. Each page and file checks payroll:archive-view itself.
 */
Route::view('/lonearkiv', 'payroll::archive')->name('payroll.archive');
Route::view('/lonearkiv/rapporter', 'payroll::archive-reports')->name('payroll.archive.reports');
Route::get('/lonearkiv/lonebesked/{payslip}', PayslipController::class)->whereNumber('payslip')->name('payroll.archive.payslip');
Route::get('/lonearkiv/arbetsgivardeklaration/{submission}/fil', AgiFileController::class)->whereNumber('submission')->name('payroll.archive.agi');
Route::get('/lonearkiv/tidigare-lonebesked/{payslip}', ImportedPayslipController::class)->whereNumber('payslip')->name('payroll.imported-payslip');
