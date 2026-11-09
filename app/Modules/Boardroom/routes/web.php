<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Behind module:boardroom: 404 while the module is off.
Route::view('/aktiebok/agare', 'boardroom::shareholders')->name('share-register.shareholders');
Route::view('/aktiebok/bolagsordning', 'boardroom::articles')->name('share-register.articles');
