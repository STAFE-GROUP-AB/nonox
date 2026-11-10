<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\ArticleImageController;
use App\Modules\Inventory\Http\LabelPrintController;
use App\Modules\Inventory\Http\PickListPdfController;
use App\Modules\Inventory\Http\PurchaseOrderController;
use App\Modules\Inventory\Http\ScannerController;
use Illuminate\Support\Facades\Route;

// Behind module:inventory: 404 while the module is off.
Route::view('/lager', 'inventory::overview')->name('inventory.overview');
Route::view('/lager/artiklar', 'inventory::articles')->name('inventory.articles');
Route::get('/lager/artiklar/{article}/bilder/{image}', ArticleImageController::class)->whereNumber(['article', 'image'])->name('inventory.articleImages.show');
Route::view('/lager/lagerstallen', 'inventory::locations')->name('inventory.locations');
Route::view('/lager/inleveranser', 'inventory::receipts')->name('inventory.receipts');
// Inköpsorder and the purchase proposal (ADR-071 §2, §5).
Route::view('/lager/inkopsorder', 'inventory::purchase-orders')->name('inventory.purchaseOrders');
Route::get('/lager/inkopsorder/ny', [PurchaseOrderController::class, 'create'])->name('inventory.purchaseOrders.create');
Route::get('/lager/inkopsorder/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->whereNumber('purchaseOrder')->name('inventory.purchaseOrders.show');
Route::get('/lager/inkopsorder/{purchaseOrder}/andra', [PurchaseOrderController::class, 'edit'])->whereNumber('purchaseOrder')->name('inventory.purchaseOrders.edit');
Route::get('/lager/inkopsorder/{purchaseOrder}/pdf', [PurchaseOrderController::class, 'pdf'])->whereNumber('purchaseOrder')->name('inventory.purchaseOrders.pdf');
Route::view('/lager/inkopsforslag', 'inventory::purchase-proposals')->name('inventory.purchaseProposals');
Route::view('/lager/plocklistor', 'inventory::pick-lists')->name('inventory.pickLists');
Route::get('/lager/plocklistor/{pickList}/pdf', PickListPdfController::class)->whereNumber('pickList')->name('inventory.pickLists.pdf');
Route::view('/lager/inventering', 'inventory::counts')->name('inventory.counts');
Route::view('/lager/rapporter', 'inventory::reports')->name('inventory.reports');
Route::view('/lager/installningar', 'inventory::settings')->name('inventory.settings');
Route::view('/lager/installningar/etiketter', 'inventory::labels')->name('inventory.labels');
Route::get('/lager/installningar/etiketter/{template}/provsida', [LabelPrintController::class, 'sample'])->whereNumber('template')->name('inventory.labels.sample');
Route::post('/lager/etiketter/utskrift', [LabelPrintController::class, 'print'])->name('inventory.labels.print');

// Skanna (ADR-040): the phone page and the JSON it calls with the session.
Route::get('/lager/skanna', [ScannerController::class, 'shell'])->name('inventory.scanner');
Route::get('/lager/skanna/data', [ScannerController::class, 'data'])->name('inventory.scanner.data');
Route::post('/lager/skanna/operationer', [ScannerController::class, 'operations'])->middleware('throttle:inventory-scanner')->name('inventory.scanner.operations');
Route::get('/lager/skanna/sok', [ScannerController::class, 'lookup'])->name('inventory.scanner.lookup');
Route::get('/lager/skanna/bild/{image}', [ScannerController::class, 'image'])->whereNumber('image')->name('inventory.scanner.image');
