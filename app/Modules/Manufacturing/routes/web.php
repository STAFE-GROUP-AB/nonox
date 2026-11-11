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
Route::view('/tillverkning/verkstadsterminal', 'manufacturing::terminal-admin')->name('manufacturing.terminal.admin');
Route::view('/tillverkning/efterkalkyl', 'manufacturing::analysis')->name('manufacturing.analysis');
