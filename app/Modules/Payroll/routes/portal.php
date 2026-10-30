<?php

declare(strict_types=1);

use App\Modules\Payroll\Http\PayslipController;
use Illuminate\Support\Facades\Route;

Route::view('/anstalld', 'payroll::portal.home')->name('payroll.portal.home');
Route::get('/anstalld/lonebesked/{payslip}', PayslipController::class)->whereNumber('payslip')->name('payroll.portal.payslip');
