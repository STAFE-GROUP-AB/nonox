<?php

declare(strict_types=1);

use App\Modules\Agreements\Http\PremiumBasisController;
use Illuminate\Support\Facades\Route;

Route::view('/lon/avtal', 'agreements::index')->name('agreements.index');
Route::view('/lon/avtal/versioner', 'agreements::versions')->name('agreements.versions');
Route::view('/lon/avtal/nonox-ai', 'agreements::ai')->name('agreements.ai');
Route::view('/lon/avtal/lokala-avtal', 'agreements::local')->name('agreements.local');
Route::view('/lon/avtal/rapporter', 'agreements::reports')->name('agreements.reports');
Route::get('/lon/avtal/rapporter/{rapport}', PremiumBasisController::class)->whereIn('rapport', ['fora', 'collectum'])->name('agreements.reports.download');
