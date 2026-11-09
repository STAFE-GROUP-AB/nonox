<?php

declare(strict_types=1);

use App\Modules\Boardroom\Http\BoardroomDocumentsController;
use Illuminate\Support\Facades\Route;

// The register, the meetings and their documents stay readable while the
// module is off (archive permissions, BoardroomModule); the pages check the
// permission and offer changes only while the module is on.
Route::view('/aktiebok', 'boardroom::register')->name('share-register.index');
Route::view('/aktiebok/handelser', 'boardroom::events')->name('share-register.events');
Route::get('/aktiebok/utskrift', [BoardroomDocumentsController::class, 'register'])->name('share-register.print');
Route::view('/styrelserum', 'boardroom::meetings')->name('boardroom.meetings');
Route::view('/styrelserum/moten/{meeting}', 'boardroom::meeting')->whereNumber('meeting')->name('boardroom.meetings.show');
Route::get('/styrelserum/moten/{meeting}/kallelse', [BoardroomDocumentsController::class, 'notice'])->whereNumber('meeting')->name('boardroom.meetings.notice');
Route::get('/styrelserum/protokoll/{minutes}', [BoardroomDocumentsController::class, 'minutes'])->whereNumber('minutes')->name('boardroom.minutes.pdf');
Route::get('/registerutdrag/aktieagare/{shareholder}', [BoardroomDocumentsController::class, 'shareholderExport'])->whereNumber('shareholder')->name('privacy.export.shareholder');
