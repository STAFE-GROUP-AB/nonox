<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\ArticleImageController;
use App\Modules\Inventory\Http\PickListPdfController;
use Illuminate\Support\Facades\Route;

// Behind module:inventory: 404 while the module is off.
Route::view('/lager', 'inventory::overview')->name('inventory.overview');
Route::view('/lager/artiklar', 'inventory::articles')->name('inventory.articles');
Route::get('/lager/artiklar/{article}/bilder/{image}', ArticleImageController::class)->whereNumber(['article', 'image'])->name('inventory.articleImages.show');
Route::view('/lager/lagerstallen', 'inventory::locations')->name('inventory.locations');
Route::view('/lager/inleveranser', 'inventory::receipts')->name('inventory.receipts');
Route::view('/lager/plocklistor', 'inventory::pick-lists')->name('inventory.pickLists');
Route::get('/lager/plocklistor/{pickList}/pdf', PickListPdfController::class)->whereNumber('pickList')->name('inventory.pickLists.pdf');
Route::view('/lager/inventering', 'inventory::counts')->name('inventory.counts');
Route::view('/lager/rapporter', 'inventory::reports')->name('inventory.reports');
Route::view('/lager/installningar', 'inventory::settings')->name('inventory.settings');
