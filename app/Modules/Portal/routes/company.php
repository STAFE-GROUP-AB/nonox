<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// The company's side of the customer portal (ADR-075 §10), behind module:portal. /kunder/{account} in CRM takes numbers only.
Route::view('/kunder/portal', 'portal::admin')->name('portal.admin');
Route::view('/kunder/portal/fragor', 'portal::questions')->name('portal.admin.questions');
