<?php

declare(strict_types=1);

use App\Modules\Crm\Http\MailingPreviewController;
use Illuminate\Support\Facades\Route;

// Behind module:crm: 404 while the module is off. /kunder itself is the core register's route (ADR-043).
Route::view('/kunder/aktiviteter', 'crm::activities')->name('crm.activities');
Route::view('/kunder/personer', 'crm::people')->name('crm.people');
Route::get('/kunder/personer/{person}', fn (int $person) => view('crm::person', ['personId' => $person]))->whereNumber('person')->name('crm.people.show');
Route::view('/kunder/installningar', 'crm::settings')->name('crm.settings');
// Stage 2: the dashboard, the pipeline board, a deal's card, and the forecast with the goals.
Route::view('/kunder/oversikt', 'crm::dashboard')->name('crm.dashboard');
Route::view('/kunder/affarer', 'crm::deals')->name('crm.deals');
Route::get('/kunder/affarer/{deal}', fn (int $deal) => view('crm::deal', ['dealId' => $deal]))->whereNumber('deal')->name('crm.deals.show');
Route::view('/kunder/prognos', 'crm::forecast')->name('crm.forecast');
// Stage 5 (ADR-048): e-mail logging, templates, segments, mailings and web forms.
Route::view('/kunder/mejl', 'crm::emails')->name('crm.emails');
Route::view('/kunder/mallar', 'crm::templates')->name('crm.templates');
Route::view('/kunder/segment', 'crm::segments')->name('crm.segments');
Route::view('/kunder/utskick', 'crm::mailings')->name('crm.mailings');
Route::get('/kunder/utskick/{mailing}', fn (int $mailing) => view('crm::mailing', ['mailingId' => $mailing]))->whereNumber('mailing')->name('crm.mailings.show');
Route::get('/kunder/utskick/{mailing}/forhandsvisning', MailingPreviewController::class)->whereNumber('mailing')->name('crm.mailings.preview');
Route::view('/kunder/formular', 'crm::forms')->name('crm.forms');
Route::get('/kunder/{account}', fn (int $account) => view('crm::account', ['accountId' => $account]))->whereNumber('account')->name('crm.accounts.show');
