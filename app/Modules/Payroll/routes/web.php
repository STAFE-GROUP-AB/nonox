<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::view('/lon', 'payroll::home')->name('payroll.index');
Route::view('/lon/regler', 'payroll::rules')->name('payroll.rules');
