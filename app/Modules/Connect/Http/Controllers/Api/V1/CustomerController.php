<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Actions\Invoicing\SaveCustomer;
use App\Models\Customer;
use App\Modules\Connect\Http\Resources\ApiPresenter;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\ConnectCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer register over the API (ADR-032), through SaveCustomer like
 * the UI. A personal number can be written but is never read back.
 */
final class CustomerController
{
    private const array FIELDS = ['name', 'org_number', 'personal_number', 'vat_number', 'email', 'phone', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'payment_terms_days', 'is_active', 'reverse_charge_construction', 'foreign_sale'];

    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return CursorList::respond($request, $query, fn (Customer $customer): array => ApiPresenter::customer($customer));
    }

    public function show(Customer $customer): JsonResponse
    {
        return CursorList::one(ApiPresenter::customer($customer));
    }

    public function store(Request $request, SaveCustomer $save): JsonResponse
    {
        $credential = $this->credential();
        $customer = $save($credential->user, $credential->company, array_intersect_key($request->json()->all(), array_flip(self::FIELDS)));

        return CursorList::one(ApiPresenter::customer($customer->refresh()), 201)
            ->header('Location', route('connect.api.customers.show', $customer));
    }

    public function update(Request $request, Customer $customer, SaveCustomer $save): JsonResponse
    {
        $credential = $this->credential();
        $current = [];

        foreach (self::FIELDS as $field) {
            $value = $customer->getAttribute($field);
            $current[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        $saved = $save($credential->user, $credential->company, [...$current, ...array_intersect_key($request->json()->all(), array_flip(self::FIELDS))], $customer);

        return CursorList::one(ApiPresenter::customer($saved->refresh()));
    }

    private function credential(): ConnectCredential
    {
        return ConnectAccess::current() ?? abort(401);
    }
}
