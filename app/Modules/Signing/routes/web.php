<?php

declare(strict_types=1);

use App\Modules\Signing\Http\UploadController;
use Illuminate\Support\Facades\Route;

// Behind module:signing: 404 while the module is off.
Route::view('/signering/ny', 'signing-module::wizard')->name('signing.new');
Route::post('/signering/ny', UploadController::class)->middleware('throttle:20,1')->name('signing.upload');
