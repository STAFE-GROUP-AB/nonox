<?php

declare(strict_types=1);

use App\Modules\Payroll\Http\PayrollRunController;
use App\Modules\Payroll\Http\PayslipController;
use Illuminate\Support\Facades\Route;

Route::view('/lon', 'payroll::home')->name('payroll.index');
Route::view('/lon/regler', 'payroll::rules')->name('payroll.rules');
Route::view('/lon/anstallda', 'payroll::employees')->name('payroll.employees');
Route::view('/lon/ansokningar', 'payroll::absence-requests')->name('payroll.requests');
Route::view('/lon/semesterar', 'payroll::vacation-year')->name('payroll.vacationYear');
Route::view('/lon/semesterskuld', 'payroll::vacation-liability')->name('payroll.vacationLiability');
Route::view('/lon/korningar', 'payroll::runs')->name('payroll.runs.index');
Route::get('/lon/korningar/{run}', PayrollRunController::class)->whereNumber('run')->name('payroll.runs.show');
Route::get('/lon/lonebesked/{payslip}', PayslipController::class)->whereNumber('payslip')->name('payroll.payslips.pdf');
