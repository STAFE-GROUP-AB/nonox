<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Behind module:manufacturing: 404 while the module is off.
Route::redirect('/tillverkning', '/tillverkning/stycklistor')->name('manufacturing.home');
Route::view('/tillverkning/stycklistor', 'manufacturing::boms')->name('manufacturing.boms.index');
Route::view('/tillverkning/stycklistor/ny', 'manufacturing::bom', ['artikel' => null])->name('manufacturing.boms.create');
Route::view('/tillverkning/stycklistor/{artikel}', 'manufacturing::bom')->whereNumber('artikel')->name('manufacturing.boms.show');
Route::view('/tillverkning/arbetssteg', 'manufacturing::operations')->name('manufacturing.operations');
Route::view('/tillverkning/installningar', 'manufacturing::settings')->name('manufacturing.settings');
