<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Behind module:manufacturing: 404 while the module is off.
Route::redirect('/tillverkning', '/tillverkning/stycklistor')->name('manufacturing.home');
Route::view('/tillverkning/stycklistor', 'manufacturing::boms')->name('manufacturing.boms.index');
Route::view('/tillverkning/stycklistor/ny', 'manufacturing::bom', ['artikel' => null])->name('manufacturing.boms.create');
Route::view('/tillverkning/stycklistor/{artikel}', 'manufacturing::bom')->whereNumber('artikel')->name('manufacturing.boms.show');
Route::view('/tillverkning/tillverkningsorder', 'manufacturing::production-orders')->name('manufacturing.productionOrders.index');
Route::view('/tillverkning/tillverkningsorder/ny', 'manufacturing::production-order-create')->name('manufacturing.productionOrders.create');
Route::view('/tillverkning/tillverkningsorder/{order}', 'manufacturing::production-order')->whereNumber('order')->name('manufacturing.productionOrders.show');
Route::view('/tillverkning/arbetssteg', 'manufacturing::operations')->name('manufacturing.operations');
Route::view('/tillverkning/installningar', 'manufacturing::settings')->name('manufacturing.settings');
Route::view('/tillverkning/efterkalkyl', 'manufacturing::analysis')->name('manufacturing.analysis');

// The planning calendar (ADR-071 §13.1); the staff schedule of ADR-037 keeps /planering.
Route::view('/tillverkning/planering', 'manufacturing::planning.page', ['screen' => 'timeline'])->name('manufacturing.planning.timeline');
Route::view('/tillverkning/planering/resurser', 'manufacturing::planning.page', ['screen' => 'resources'])->name('manufacturing.planning.resources');
Route::view('/tillverkning/planering/kalender', 'manufacturing::planning.page', ['screen' => 'calendar'])->name('manufacturing.planning.calendar');
Route::view('/tillverkning/planering/ordrar', 'manufacturing::planning.page', ['screen' => 'orders'])->name('manufacturing.planning.orders');
Route::view('/tillverkning/planering/avvikelser', 'manufacturing::planning.page', ['screen' => 'deviations'])->name('manufacturing.planning.deviations');
Route::view('/tillverkning/planering/tank-om', 'manufacturing::planning.page', ['screen' => 'scenario'])->name('manufacturing.planning.scenario');
Route::view('/tillverkning/planering/installningar', 'manufacturing::planning.page', ['screen' => 'setup'])->name('manufacturing.planning.setup');
