<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Models\VatNumberCheck;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Vies\Vies;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Checks a customer's VAT number in VIES and keeps the answer as evidence.
 * An invoice without Swedish VAT to a customer whose latest check failed
 * cannot be issued (IssueInvoice).
 *
 * TODO(legal-verify): whether a check is required before every VAT-free EU
 * sale, and how old a check may be.
 */
class CheckVatNumber
{
    public function __construct(private readonly Vies $vies) {}

    public function __invoke(User $user, Company $company, Customer $customer): VatNumberCheck
    {
        Gate::forUser($user)->authorize('update', $customer);

        $vatNumber = strtoupper(trim((string) $customer->vat_number));

        if ($vatNumber === '') {
            throw ValidationException::withMessages(['vat_number' => __('Kunden saknar momsregistreringsnummer.')]);
        }

        try {
            $answer = $this->vies->check($vatNumber, $company->vat_number);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['vat_number' => __('VIES svarar inte just nu, eller landets register är nere. Försök igen om en stund.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $customer, $vatNumber, $answer): VatNumberCheck {
            $check = VatNumberCheck::create([
                'customer_id' => $customer->id,
                'vat_number' => $vatNumber,
                ...$answer,
                'checked_at' => CarbonImmutable::now(),
                'checked_by' => $user->id,
            ]);

            Audit::record('customer.vat_number_checked', $customer, [
                'vat_number' => $vatNumber,
                'valid' => $answer['valid'],
                'request_identifier' => $answer['request_identifier'],
            ], $company, $user);

            return $check;
        });
    }
}
