<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Models\InvoicePayment;
use App\Models\SupplierPayment;
use App\Modules\Connect\Http\Resources\ApiPresenter;
use App\Modules\Connect\Http\Resources\CursorList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Payments over the API (ADR-032), read-only: money received for customer
 * invoices and money paid for supplier invoices, each with the voucher that
 * booked it. A payment is registered on its invoice
 * (POST /invoices/{id}/payments, POST /supplier-invoices/{id}/payments) and
 * never changed; a mistake is corrected through its voucher.
 */
final class PaymentController
{
    public function incoming(Request $request): JsonResponse
    {
        $query = InvoicePayment::query()->with('account');
        $this->filter($request, $query);

        return CursorList::respond($request, $query, fn (InvoicePayment $payment): array => ApiPresenter::invoicePayment($payment));
    }

    public function outgoing(Request $request): JsonResponse
    {
        $query = SupplierPayment::query()->with('account');
        $this->filter($request, $query);

        return CursorList::respond($request, $query, fn (SupplierPayment $payment): array => ApiPresenter::supplierPayment($payment));
    }

    /**
     * @param  Builder<InvoicePayment>|Builder<SupplierPayment>  $query
     */
    private function filter(Request $request, Builder $query): void
    {
        $filters = Validator::make($request->query(), [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();

        if (filled($filters['date_from'] ?? null)) {
            $query->where('paid_on', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->where('paid_on', '<=', $filters['date_to']);
        }
    }
}
