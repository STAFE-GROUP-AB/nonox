<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::view('/anslutningar', 'connect::index')->name('connect.index');
