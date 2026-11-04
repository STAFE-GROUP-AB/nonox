<?php

declare(strict_types=1);

use App\Http\Controllers\DeadlineFeedController;
use App\Support\Guide\Guide;
use Illuminate\Support\Facades\Route;

// Mina datum's calendar link (ADR-051, stage 2): no session and no login, the secret token is the key. Throttled.
Route::get('/foretagsguiden/'.Guide::CALENDAR_SLUG.'/{token}.ics', DeadlineFeedController::class)->where('token', '[A-Za-z0-9]{48}')->name('guide.dates.feed');
