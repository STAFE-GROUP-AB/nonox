<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Behind module:crm: 404 while the module is off. /kunder itself is the core register's route (ADR-043).
Route::view('/kunder/aktiviteter', 'crm::activities')->name('crm.activities');
Route::view('/kunder/personer', 'crm::people')->name('crm.people');
Route::view('/kunder/installningar', 'crm::settings')->name('crm.settings');
// Stage 2: the dashboard, the pipeline board, a deal's card, and the forecast with the goals.
Route::view('/kunder/oversikt', 'crm::dashboard')->name('crm.dashboard');
Route::view('/kunder/affarer', 'crm::deals')->name('crm.deals');
Route::get('/kunder/affarer/{deal}', fn (int $deal) => view('crm::deal', ['dealId' => $deal]))->whereNumber('deal')->name('crm.deals.show');
Route::view('/kunder/prognos', 'crm::forecast')->name('crm.forecast');
Route::get('/kunder/{account}', fn (int $account) => view('crm::account', ['accountId' => $account]))->whereNumber('account')->name('crm.accounts.show');
