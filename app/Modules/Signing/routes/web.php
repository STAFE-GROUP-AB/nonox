<?php

declare(strict_types=1);

use App\Modules\Signing\Http\BulkSendExampleController;
use App\Modules\Signing\Http\DocumentPdfController;
use App\Modules\Signing\Http\UploadController;
use Illuminate\Support\Facades\Route;

// Behind module:signing: 404 while the module is off.
Route::view('/signering/ny', 'signing-module::wizard')->name('signing.new');
Route::post('/signering/ny', UploadController::class)->middleware('throttle:20,1')->name('signing.upload');
// Templates with roles (ADR-046 S14). "ny" before the key, so that it is never taken for a template.
Route::view('/signering/mallar', 'signing-module::templates')->name('signing.templates');
Route::view('/signering/mallar/ny', 'signing-module::template', ['template' => null])->name('signing.template.new');
Route::view('/signering/mallar/{template}', 'signing-module::template')->name('signing.template');
// Bulk send from a template (ADR-046 S15). The fixed paths before the run's key. signing:manage inside.
Route::view('/signering/massutskick', 'signing-module::bulk-sends')->name('signing.bulk-sends');
Route::get('/signering/massutskick/exempel/{template}', BulkSendExampleController::class)->middleware('throttle:30,1')->name('signing.bulk-sends.example');
Route::view('/signering/massutskick/{bulkSend}', 'signing-module::bulk-send')->name('signing.bulk-send');
// The PDF the field editor shows while the sender places fields (ADR-046 S8).
Route::get('/signering/ny/dokument', DocumentPdfController::class)->middleware('throttle:60,1')->name('signing.new.pdf');
