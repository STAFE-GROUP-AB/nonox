<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::view('/lon/avtal', 'agreements::index')->name('agreements.index');
Route::view('/lon/avtal/versioner', 'agreements::versions')->name('agreements.versions');
Route::view('/lon/avtal/nonox-ai', 'agreements::ai')->name('agreements.ai');
