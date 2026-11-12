<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Not behind module:manufacturing: the terminal's people must be reachable for
// withdrawing a photo, archiving or deleting a person and fetching the register
// extract also while the module is off, as long as reported time is kept
// (ADR-071 §7, GDPR art. 7.3, 15 and 17; ManufacturingModule::archivePermissions).
Route::view('/tillverkning/verkstadsterminal', 'manufacturing::terminal-admin')->name('manufacturing.terminal.admin');
