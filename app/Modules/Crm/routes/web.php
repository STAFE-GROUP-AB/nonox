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
// Stage 4 (ADR-047): prospecting in the company register (search and look-alike), and call lists.
Route::view('/kunder/prospektera', 'crm::prospecting')->name('crm.prospecting');
Route::view('/kunder/ringlistor', 'crm::call-lists')->name('crm.call-lists');
Route::get('/kunder/ringlistor/{callList}', fn (int $callList) => view('crm::call-list', ['callListId' => $callList]))->whereNumber('callList')->name('crm.call-lists.show');
// Stage 3 (ADR-046): the agreement register, quote templates and the documents of signing.
Route::view('/kunder/avtal', 'crm::agreements')->name('crm.agreements');
Route::get('/kunder/avtal/nytt', fn () => view('crm::agreement', ['agreementId' => null]))->name('crm.agreements.create');
Route::get('/kunder/avtal/{agreement}', fn (int $agreement) => view('crm::agreement', ['agreementId' => $agreement]))->whereNumber('agreement')->name('crm.agreements.show');
Route::view('/kunder/offertmallar', 'crm::quote-templates')->name('crm.quoteTemplates');
// Stage 5 (ADR-048): e-mail logging, templates, segments, mailings and web forms.
Route::view('/kunder/mejl', 'crm::emails')->name('crm.emails');
Route::view('/kunder/mallar', 'crm::templates')->name('crm.templates');
Route::view('/kunder/segment', 'crm::segments')->name('crm.segments');
Route::view('/kunder/utskick', 'crm::mailings')->name('crm.mailings');
Route::get('/kunder/utskick/{mailing}', fn (int $mailing) => view('crm::mailing', ['mailingId' => $mailing]))->whereNumber('mailing')->name('crm.mailings.show');
Route::get('/kunder/utskick/{mailing}/forhandsvisning', MailingPreviewController::class)->whereNumber('mailing')->name('crm.mailings.preview');
Route::view('/kunder/formular', 'crm::forms')->name('crm.forms');
Route::get('/kunder/{account}', fn (int $account) => view('crm::account', ['accountId' => $account]))->whereNumber('account')->name('crm.accounts.show');
