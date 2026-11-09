<?php

declare(strict_types=1);

use App\Modules\Saas\Http\SaasCsvController;
use Illuminate\Support\Facades\Route;

// Behind module:saas: every page answers 404 while the module is off (ADR-080 §1).
Route::view('/aterkommande-intakter', 'saas::page', ['livewire' => 'saas.overview'])->name('saas.overview');
Route::view('/aterkommande-intakter/rorelser', 'saas::page', ['livewire' => 'saas.movements'])->name('saas.movements');
Route::get('/aterkommande-intakter/rorelser/{month}', fn (string $month) => view('saas::page', ['livewire' => 'saas.movement', 'parameters' => ['month' => $month]]))
    ->where('month', '\d{4}-\d{2}')
    ->name('saas.movements.show');
Route::view('/aterkommande-intakter/kunder', 'saas::page', ['livewire' => 'saas.customers'])->name('saas.customers');
Route::get('/aterkommande-intakter/kunder/{customer}', fn (int $customer) => view('saas::page', ['livewire' => 'saas.customer', 'parameters' => ['customerId' => $customer]]))
    ->whereNumber('customer')
    ->name('saas.customers.show');
Route::view('/aterkommande-intakter/kohorter', 'saas::page', ['livewire' => 'saas.cohorts'])->name('saas.cohorts');
Route::view('/aterkommande-intakter/uppsagda', 'saas::page', ['livewire' => 'saas.scheduled'])->name('saas.scheduled');
Route::view('/aterkommande-intakter/rattelser', 'saas::page', ['livewire' => 'saas.corrections'])->name('saas.corrections');
Route::view('/aterkommande-intakter/manader', 'saas::page', ['livewire' => 'saas.months'])->name('saas.months');
Route::view('/aterkommande-intakter/kallor', 'saas::page', ['livewire' => 'saas.sources'])->name('saas.sources');
Route::get('/aterkommande-intakter/export/{list}', SaasCsvController::class)
    ->whereIn('list', SaasCsvController::LISTS)
    ->name('saas.export');
