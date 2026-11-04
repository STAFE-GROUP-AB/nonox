<?php

declare(strict_types=1);

use App\Modules\Crm\Http\ActivityCalendarFileController;
use App\Modules\Crm\Http\CalendarConnectController;
use Illuminate\Support\Facades\Route;

// Stage 6 (ADR-049), behind module:crm like the rest: the calendar page, one activity as an .ics file, and the OAuth round trip.
Route::view('/kunder/kalender', 'crm::calendar')->name('crm.calendar');
Route::get('/kunder/aktiviteter/{activity}/kalender.ics', ActivityCalendarFileController::class)->whereNumber('activity')->name('crm.activities.ics');
Route::get('/kunder/kalender/koppla/{provider}', [CalendarConnectController::class, 'redirect'])->whereIn('provider', ['google', 'microsoft'])->name('crm.calendar.connect');
Route::get('/kunder/kalender/koppla/{provider}/svar', [CalendarConnectController::class, 'callback'])->whereIn('provider', ['google', 'microsoft'])->name('crm.calendar.callback');
