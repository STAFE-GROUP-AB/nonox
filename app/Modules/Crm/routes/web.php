<?php

declare(strict_types=1);

use App\Modules\Crm\Http\CrmPersonExportController;
use Illuminate\Support\Facades\Route;

// Behind module:crm: 404 while the module is off. /kunder itself is the core register's route (ADR-043).
Route::view('/kunder/aktiviteter', 'crm::activities')->name('crm.activities');
Route::view('/kunder/personer', 'crm::people')->name('crm.people');
Route::view('/kunder/installningar', 'crm::settings')->name('crm.settings');
Route::get('/kunder/{account}', fn (int $account) => view('crm::account', ['accountId' => $account]))->whereNumber('account')->name('crm.accounts.show');
Route::get('/registerutdrag/kontaktperson/{person}', CrmPersonExportController::class)->whereNumber('person')->name('privacy.export.crmPerson');
