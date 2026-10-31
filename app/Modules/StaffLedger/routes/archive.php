<?php

declare(strict_types=1);

use App\Modules\StaffLedger\Http\ControlVisitExportController;
use Illuminate\Support\Facades\Route;

// The ledger and the control visit stay available for the retention period,
// also while the module is off (archive permissions, StaffLedgerModule).
Route::view('/personalliggare/liggare', 'staff-ledger::ledger')->name('staff-ledger.ledger');
Route::view('/personalliggare/kontrollbesok', 'staff-ledger::control-visit')->name('staff-ledger.control-visit');
Route::get('/personalliggare/kontrollbesok/export/{format}', ControlVisitExportController::class)
    ->whereIn('format', ['pdf', 'csv', 'xml'])
    ->name('staff-ledger.control-visit.export');
