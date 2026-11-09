<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Behind module:kitchen: 404 while the module is off.
Route::redirect('/kok', '/kok/recept')->name('kitchen.home');
Route::view('/kok/recept', 'kitchen::recipes')->name('kitchen.recipes.index');
Route::view('/kok/recept/nytt', 'kitchen::recipe', ['recept' => null])->name('kitchen.recipes.create');
Route::view('/kok/recept/{recept}', 'kitchen::recipe')->whereNumber('recept')->name('kitchen.recipes.show');
Route::view('/kok/meny', 'kitchen::menu')->name('kitchen.menu');
Route::view('/kok/svinn', 'kitchen::waste')->name('kitchen.waste');
Route::view('/kok/personalmat', 'kitchen::meals')->name('kitchen.meals');
Route::view('/kok/avvikelser', 'kitchen::variance')->name('kitchen.variance');
Route::view('/kok/installningar', 'kitchen::settings')->name('kitchen.settings');
