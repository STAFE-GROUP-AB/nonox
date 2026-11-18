<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Not behind module:signing: signed standalone documents are readable with signing:view also while the module is off
// (the permission is an archive permission). The pages themselves show only what the module's state allows.
Route::view('/signering', 'signing-module::envelopes')->name('signing.envelopes');
Route::view('/signering/{envelope}', 'signing-module::envelope')->whereUuid('envelope')->name('signing.envelope');
