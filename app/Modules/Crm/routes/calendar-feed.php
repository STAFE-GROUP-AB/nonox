<?php

declare(strict_types=1);

use App\Modules\Crm\Http\CalendarFeedController;
use Illuminate\Support\Facades\Route;

// No session and no login: the secret token in the link is the key (ADR-049). Throttled.
Route::get('/kunder/kalender/{token}.ics', CalendarFeedController::class)->where('token', '[A-Za-z0-9]{48}')->name('crm.calendar.feed');
