<?php

declare(strict_types=1);

use App\Modules\Inbox\Http\InboxFileController;
use Illuminate\Support\Facades\Route;

Route::view('/inkorg', 'inbox::index')->name('inbox.index');
Route::get('/inkorg/{message}/original', [InboxFileController::class, 'original'])->whereUuid('message')->name('inbox.original');
Route::get('/inkorg/{message}/bilagor/{attachment}', [InboxFileController::class, 'attachment'])->whereUuid('message')->whereNumber('attachment')->name('inbox.attachment');
