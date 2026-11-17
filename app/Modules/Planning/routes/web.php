<?php

declare(strict_types=1);

use App\Modules\Planning\Http\StaffingCsvController;
use Illuminate\Support\Facades\Route;

// Behind module:planning: 404 while the module is off.
Route::view('/planering', 'planning::schedule')->name('planning.schedule');
Route::view('/planering/mallar', 'planning::templates')->name('planning.templates');
Route::view('/planering/uppgifter', 'planning::tasks')->name('planning.tasks');
Route::view('/planering/installningar', 'planning::settings')->name('planning.settings');
Route::view('/planering/bemanning', 'planning::staffing')->name('planning.staffing');
Route::get('/planering/bemanning/csv', StaffingCsvController::class)->name('planning.staffing.csv');
Route::view('/planering/bemanningsbehov', 'planning::demand')->name('planning.demand');
Route::view('/planering/forfragningar', 'planning::requests')->name('planning.requests');
Route::view('/planering/attest', 'planning::attest')->name('planning.attest');
Route::view('/planering/forslag', 'planning::proposals')->name('planning.proposals');
Route::view('/planering/meddelanden', 'planning::messages')->name('planning.messages');
