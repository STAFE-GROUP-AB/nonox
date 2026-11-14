<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Http\Middleware\AuthenticateWallScreen;
use App\Modules\Manufacturing\Http\WallScreenController;
use Illuminate\Support\Facades\Route;

// The workshop wall screen (ADR-071, M7). No session and no login: the page
// is the same for every screen, and the board is read with the screen's
// device token. Throttled. Not behind the module middleware: a company with
// the module off answers 404 from the device middleware.
Route::get('/verkstad/storskarm', [WallScreenController::class, 'shell'])->name('manufacturing.wallScreen');
Route::post('/verkstad/storskarm/koppla', [WallScreenController::class, 'pair'])->middleware('throttle:workshop-pairing')->name('manufacturing.wallScreen.pair');
Route::get('/verkstad/storskarm/tavla', [WallScreenController::class, 'board'])->middleware(AuthenticateWallScreen::class)->name('manufacturing.wallScreen.board');
