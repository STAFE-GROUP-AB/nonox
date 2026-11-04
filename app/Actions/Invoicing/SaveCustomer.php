<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Company\OrganisationNumber;
use App\Domain\Invoicing\ForeignSale;
use App\Models\Company;
use App\Models\Customer;
use App\Models\NumberSeries;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Luhn;
use App\Support\Peppol\PeppolIdentifier;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates a customer with the next customer number, or updates one.
 */
class SaveCustomer
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?Customer $customer = null): Customer
    {
        $customer === null
            ? Gate::forUser($user)->authorize('create', [Customer::class, $company])
            : Gate::forUser($user)->authorize('update', $customer);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'org_number' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (OrganisationNumber::tryFrom((string) $value) === null) {
                    $fail(__('Organisationsnumret stämmer inte. Kontrollera siffrorna.'));
                }
            }],
            'personal_number' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                $digits = substr((string) preg_replace('/\D/', '', (string) $value), -10);

                if (strlen($digits) !== 10 || ! Luhn::isValid($digits)) {
                    $fail(__('Personnumret stämmer inte. Kontrollera siffrorna.'));
                }
            }],
            'vat_number' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z]{2}[0-9A-Z]{2,18}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['required', 'integer', 'between:0,365'],
            'is_active' => ['sometimes', 'boolean'],
            'reverse_charge_construction' => ['sometimes', 'boolean'],
            'country' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'foreign_sale' => ['nullable', Rule::enum(ForeignSale::class)],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            // Peppol (ADR-050): the customer's participant id, and whether invoices go there by default.
            'peppol_id' => ['nullable', 'string', 'max:80', function (string $attribute, mixed $value, \Closure $fail): void {
                if (PeppolIdentifier::tryFrom((string) $value) === null) {
                    $fail(__('Peppol-ID:t stämmer inte. Skriv schema och nummer, till exempel 0007:5599000006.'));
                }
            }],
            'invoice_delivery' => ['nullable', 'in:EMAIL,PEPPOL'],
        ], [
            'currency.regex' => __('Valutan skrivs med tre bokstäver, till exempel SEK, EUR eller USD.'),
            'country.regex' => __('Ange landet som en landskod med två bokstäver, till exempel SE, DE eller US.'),
            'vat_number.regex' => __('Momsregistreringsnumret ska börja med landskoden, till exempel SE556677889901.'),
        ], [
            'name' => __('namn'),
            'email' => __('e-post'),
            'payment_terms_days' => __('betalningsvillkor'),
        ])->validate();

        $attributes = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $validated);

        if (array_key_exists('reverse_charge_construction', $attributes)) {
            $attributes['reverse_charge_construction'] = (bool) $attributes['reverse_charge_construction'];
        }

        if (array_key_exists('currency', $attributes)) {
            $attributes['currency'] = strtoupper((string) ($attributes['currency'] ?? 'SEK'));
        }

        if (array_key_exists('country', $attributes)) {
            $attributes['country'] = strtoupper((string) ($attributes['country'] ?? 'SE'));
        }

        if (isset($attributes['peppol_id'])) {
            $attributes['peppol_id'] = PeppolIdentifier::tryFrom((string) $attributes['peppol_id'])?->toString();
        }

        // A Swedish customer invoiced via Peppol is addressed by its organisation number unless another id is given.
        if (($attributes['invoice_delivery'] ?? null) === 'PEPPOL' && array_key_exists('peppol_id', $attributes) && $attributes['peppol_id'] === null && strtoupper((string) ($attributes['country'] ?? ($customer === null ? 'SE' : $customer->country))) === 'SE') {
            $org = OrganisationNumber::tryFrom((string) ($attributes['org_number'] ?? $customer?->org_number));
            $attributes['peppol_id'] = $org === null || $org->isPersonalIdentityNumber() ? null : PeppolIdentifier::forOrganisationNumber($org->digits)?->toString();
        }

        if (($attributes['invoice_delivery'] ?? null) === 'PEPPOL' && ($attributes['peppol_id'] ?? (array_key_exists('peppol_id', $attributes) ? null : $customer?->peppol_id)) === null) {
            throw ValidationException::withMessages(['peppol_id' => __('Fyll i kundens Peppol-ID för att fakturera via Peppol.')]);
        }

        if (array_key_exists('invoice_delivery', $attributes)) {
            $attributes['invoice_delivery'] ??= 'EMAIL';
        }

        if (isset($attributes['org_number'])) {
            $attributes['org_number'] = OrganisationNumber::tryFrom((string) $attributes['org_number'])?->formatted();
        }

        return DB::transaction(fn (): Customer => CurrentCompany::run($company, function () use ($attributes, $customer, $company, $user): Customer {
            if ($customer === null) {
                $customer = Customer::create([...$attributes, 'number' => NumberSeries::take(NumberSeries::CUSTOMERS)]);

                Audit::record('customer.created', $customer, ['number' => $customer->number, 'name' => $customer->name], $company, $user);

                return $customer;
            }

            $before = $customer->only(array_keys($attributes));
            $customer->update($attributes);

            Audit::record('customer.updated', $customer, Audit::changes(
                array_diff_key($before, ['personal_number' => true, 'peppol_id' => true]),
                array_diff_key($customer->only(array_keys($attributes)), ['personal_number' => true, 'peppol_id' => true]),
            ), $company, $user);

            return $customer;
        }));
    }
}
