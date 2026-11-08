<?php

declare(strict_types=1);

use App\Modules\WorkOrders\Http\WorkOrderDocumentsController;
use Illuminate\Support\Facades\Route;

// Decided ÄTA are räkenskapsinformation (AO-R25): readable with work-orders:view also while the module is off.
Route::get('/arbetsorder/{workOrder}/ata/{changeOrder}.pdf', [WorkOrderDocumentsController::class, 'changeOrder'])->whereNumber(['workOrder', 'changeOrder'])->name('workOrders.changeOrderPdf');
