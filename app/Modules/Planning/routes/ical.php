<?php

declare(strict_types=1);

use App\Modules\Planning\Http\IcalFeedController;
use Illuminate\Support\Facades\Route;

// No session and no login: the secret token in the link is the key. Throttled.
Route::get('/planering/kalender/{token}.ics', IcalFeedController::class)->where('token', '[A-Za-z0-9]{48}')->name('planning.ical');
