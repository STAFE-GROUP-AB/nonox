<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Actions\Payables\SaveSupplier;
use App\Models\Supplier;
use App\Modules\Connect\Http\Resources\ApiPresenter;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\ConnectCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The supplier register over the API (ADR-032), through SaveSupplier like
 * the UI.
 */
final class SupplierController
{
    private const array FIELDS = ['name', 'org_number', 'vat_number', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'country', 'bankgiro', 'plusgiro', 'iban', 'bic', 'payment_terms_days', 'default_account', 'is_active'];

    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query()->with('defaultAccount');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return CursorList::respond($request, $query, fn (Supplier $supplier): array => ApiPresenter::supplier($supplier));
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return CursorList::one(ApiPresenter::supplier($supplier->load('defaultAccount')));
    }

    public function store(Request $request, SaveSupplier $save): JsonResponse
    {
        $credential = $this->credential();
        $supplier = $save($credential->user, $credential->company, array_intersect_key($request->json()->all(), array_flip(self::FIELDS)));

        return CursorList::one(ApiPresenter::supplier($supplier->refresh()->load('defaultAccount')), 201)
            ->header('Location', route('connect.api.suppliers.show', $supplier));
    }

    public function update(Request $request, Supplier $supplier, SaveSupplier $save): JsonResponse
    {
        $credential = $this->credential();
        $current = ApiPresenter::supplier($supplier->load('defaultAccount'));
        $flat = [
            ...array_intersect_key($current, array_flip(self::FIELDS)),
            'address_line1' => $current['address']['line1'],
            'postal_code' => $current['address']['postal_code'],
            'city' => $current['address']['city'],
            'country' => $current['address']['country'],
        ];

        $saved = $save($credential->user, $credential->company, [...$flat, ...array_intersect_key($request->json()->all(), array_flip(self::FIELDS))], $supplier);

        return CursorList::one(ApiPresenter::supplier($saved->refresh()->load('defaultAccount')));
    }

    private function credential(): ConnectCredential
    {
        return ConnectAccess::current() ?? abort(401);
    }
}
