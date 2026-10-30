<?php

declare(strict_types=1);

use App\Modules\Payroll\Http\AgiFileController;
use App\Modules\Payroll\Http\EmployerCertificateController;
use App\Modules\Payroll\Http\ExpenseReceiptController;
use App\Modules\Payroll\Http\PayrollRunController;
use App\Modules\Payroll\Http\PayslipController;
use App\Modules\Payroll\Http\SalaryPaymentFileController;
use Illuminate\Support\Facades\Route;

Route::view('/lon', 'payroll::home')->name('payroll.index');
Route::view('/lon/regler', 'payroll::rules')->name('payroll.rules');
Route::view('/lon/anstallda', 'payroll::employees')->name('payroll.employees');
Route::get('/lon/anstallda/{employee}/arbetsgivarintyg', EmployerCertificateController::class)->whereNumber('employee')->name('payroll.employees.certificate');
Route::view('/lon/ansokningar', 'payroll::absence-requests')->name('payroll.requests');
Route::get('/lon/utlagg/{expense}/kvitto', ExpenseReceiptController::class)->whereNumber('expense')->name('payroll.expenses.receipt');
Route::view('/lon/semesterar', 'payroll::vacation-year')->name('payroll.vacationYear');
Route::view('/lon/semesterskuld', 'payroll::vacation-liability')->name('payroll.vacationLiability');
Route::view('/lon/korningar', 'payroll::runs')->name('payroll.runs.index');
Route::get('/lon/korningar/{run}', PayrollRunController::class)->whereNumber('run')->name('payroll.runs.show');
Route::get('/lon/korningar/{run}/lonefil', SalaryPaymentFileController::class)->whereNumber('run')->name('payroll.runs.payment-file');
Route::view('/lon/arbetsgivardeklaration', 'payroll::agi')->name('payroll.agi');
Route::get('/lon/arbetsgivardeklaration/{submission}/fil', AgiFileController::class)->whereNumber('submission')->name('payroll.agi.file');
Route::get('/lon/lonebesked/{payslip}', PayslipController::class)->whereNumber('payslip')->name('payroll.payslips.pdf');
