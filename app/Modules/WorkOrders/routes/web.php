<?php

declare(strict_types=1);

use App\Modules\WorkOrders\Http\WorkOrderDocumentsController;
use Illuminate\Support\Facades\Route;

// Behind module:work_orders: 404 while the module is off.
Route::view('/arbetsorder', 'work-orders::index')->name('workOrders.index');
Route::view('/arbetsorder/ny', 'work-orders::create')->name('workOrders.create');
Route::view('/arbetsorder/mina', 'work-orders::mine')->name('workOrders.mine');
Route::view('/arbetsorder/pagaende-arbeten', 'work-orders::ongoing')->name('workOrders.ongoing');
Route::view('/arbetsorder/installningar', 'work-orders::settings')->name('workOrders.settings');
Route::get('/arbetsorder/{workOrder}', [WorkOrderDocumentsController::class, 'show'])->whereNumber('workOrder')->name('workOrders.show');
Route::get('/arbetsorder/{workOrder}/andra', [WorkOrderDocumentsController::class, 'edit'])->whereNumber('workOrder')->name('workOrders.edit');
Route::get('/arbetsorder/{workOrder}/pa-plats', [WorkOrderDocumentsController::class, 'field'])->whereNumber('workOrder')->name('workOrders.field');
Route::get('/arbetsorder/{workOrder}/underlag', [WorkOrderDocumentsController::class, 'specification'])->whereNumber('workOrder')->name('workOrders.specification');
Route::get('/arbetsorder/{workOrder}/foton/{photo}', [WorkOrderDocumentsController::class, 'photo'])->whereNumber(['workOrder', 'photo'])->name('workOrders.photo');
