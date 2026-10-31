<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Domain\Invoicing\AgreementStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\RecurringInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Restriction of processing (GDPR art. 18): the entry stays stored, and
 * what is already booked stays as räkenskapsinformation, but no new
 * invoices, quotes, agreements or supplier invoices can use it until the
 * restriction is lifted. Collecting what is already invoiced continues
 * (art. 18.2, legal claims).
 *
 * TODO(legal-verify): whether a supplier invoice that arrives during a
 * restriction must still be booked under Bokföringslagen, and so be allowed.
 */
class RestrictProcessing
{
    public function customer(User $user, Company $company, Customer $customer, bool $restrict): void
    {
        Gate::forUser($user)->authorize('update', $customer);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $customer, $restrict): void {
            if ($restrict && RecurringInvoice::query()->where('customer_id', $customer->id)->where('status', AgreementStatus::Active->value)->exists()) {
                throw ValidationException::withMessages(['restrict' => __('Kunden har ett pågående avtal. Avsluta avtalet först.')]);
            }

            $customer->forceFill(['processing_restricted_at' => $restrict ? now() : null])->save();

            Audit::record($restrict ? 'privacy.restricted' : 'privacy.restriction_lifted', $customer, ['subject' => 'customer'], $company, $user);
        }));
    }

    public function supplier(User $user, Company $company, Supplier $supplier, bool $restrict): void
    {
        Gate::forUser($user)->authorize('update', $supplier);

        CurrentCompany::run($company, function () use ($user, $company, $supplier, $restrict): void {
            $supplier->forceFill(['processing_restricted_at' => $restrict ? now() : null])->save();

            Audit::record($restrict ? 'privacy.restricted' : 'privacy.restriction_lifted', $supplier, ['subject' => 'supplier'], $company, $user);
        });
    }
}
