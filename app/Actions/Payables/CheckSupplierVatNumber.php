<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Vies\Vies;
use App\Support\WorkQuota;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Checks a supplier's VAT number in VIES, for example before booking a
 * purchase from another EU country with reverse charge. Unlike a
 * customer's check (CheckVatNumber) nothing blocks on the answer, so it is
 * kept only in the audit log, with VIES's consultation number.
 */
class CheckSupplierVatNumber
{
    public function __construct(private readonly Vies $vies) {}

    /**
     * @return array{valid: bool, name: ?string, address: ?string, request_identifier: ?string, vat_number: string}
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, Supplier $supplier, string $vatNumber): array
    {
        Gate::forUser($user)->authorize('update', $supplier);

        $vatNumber = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $vatNumber));

        if (! preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $vatNumber)) {
            throw ValidationException::withMessages(['vat_number' => __('Skriv momsregistreringsnumret med landskod först, till exempel DE123456789.')]);
        }

        WorkQuota::take($company, 'vies', 'vat_number');

        try {
            $answer = $this->vies->check($vatNumber, $company->vat_number);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['vat_number' => __('VIES svarar inte just nu, eller landets register är nere. Försök igen om en stund.')]);
        }

        Audit::record('supplier.vat_number_checked', $supplier, [
            'vat_number' => $vatNumber,
            'valid' => $answer['valid'],
            'request_identifier' => $answer['request_identifier'],
        ], $company, $user);

        return [...$answer, 'vat_number' => $vatNumber];
    }
}
