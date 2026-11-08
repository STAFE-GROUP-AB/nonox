<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Domain\Company\GiroNumber;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Payables\Iban;
use App\Models\Account;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates a supplier with the next supplier number, or updates one. Bankgiro
 * and plusgiro are checked with their check digit, so a payment never goes
 * to a mistyped number.
 */
class SaveSupplier
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?Supplier $supplier = null): Supplier
    {
        $supplier === null
            ? Gate::forUser($user)->authorize('create', [Supplier::class, $company])
            : Gate::forUser($user)->authorize('update', $supplier);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            // The number the supplier had in the previous program (ADR-076); only importers write it.
            'external_ref' => ['nullable', 'string', 'max:100'],
            'org_number' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (OrganisationNumber::tryFrom((string) $value) === null) {
                    $fail(__('Organisationsnumret stämmer inte. Kontrollera siffrorna.'));
                }
            }],
            'vat_number' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z]{2}[0-9A-Z]{2,18}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'payment_code' => ['nullable', 'string', 'regex:/^\d{3}$/'],
            'bankgiro' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (GiroNumber::bankgiro((string) $value) === null) {
                    $fail(__('Bankgironumret stämmer inte. Kontrollera siffrorna.'));
                }
            }],
            'plusgiro' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (GiroNumber::plusgiro((string) $value) === null) {
                    $fail(__('Plusgironumret stämmer inte. Kontrollera siffrorna.'));
                }
            }],
            'iban' => ['nullable', 'string', 'max:42', function (string $attribute, mixed $value, Closure $fail): void {
                $problem = Iban::problem((string) $value);

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
            'bic' => ['nullable', 'string', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/i'],
            'payment_terms_days' => ['required', 'integer', 'between:0,365'],
            'default_account' => ['nullable', 'integer', 'between:1000,8999'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'vat_number.regex' => __('Momsregistreringsnumret ska börja med landskoden, till exempel SE556677889901.'),
            'iban.regex' => __('IBAN ska börja med landskoden, till exempel SE45 5000 0000 0583 9825 7466.'),
            'country.size' => __('Landet skrivs med landskod på två bokstäver, till exempel DE.'),
            'country.alpha' => __('Landet skrivs med landskod på två bokstäver, till exempel DE.'),
            'payment_code.regex' => __('Betalningskoden är tre siffror, till exempel 410.'),
            'bic.regex' => __('BIC ska vara 8 eller 11 tecken, till exempel ESSESESS.'),
        ], [
            'name' => __('namn'),
            'email' => __('e-post'),
            'payment_terms_days' => __('betalningsvillkor'),
            'default_account' => __('konto'),
        ])->validate();

        // A country that is typed must be the IBAN's country; left empty, the supplier is placed by its IBAN when paying.
        $typedCountry = strtoupper(trim((string) ($input['country'] ?? '')));
        $ibanCountry = Iban::country((string) ($validated['iban'] ?? ''));

        if ($typedCountry !== '' && $ibanCountry !== null && $typedCountry !== $ibanCountry) {
            throw ValidationException::withMessages(['iban' => __('IBAN börjar med :iban men landet är :country. Kontrollera landet eller IBAN.', ['iban' => $ibanCountry, 'country' => $typedCountry])]);
        }

        $attributes = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $validated);

        foreach (['org_number' => fn (string $value) => OrganisationNumber::tryFrom($value)?->formatted(), 'bankgiro' => GiroNumber::bankgiro(...), 'plusgiro' => GiroNumber::plusgiro(...)] as $field => $format) {
            if (isset($attributes[$field])) {
                $attributes[$field] = $format((string) $attributes[$field]);
            }
        }

        if (array_key_exists('country', $attributes)) {
            $attributes['country'] = strtoupper((string) ($attributes['country'] ?? 'SE'));
        }

        foreach (['iban', 'bic'] as $field) {
            if (isset($attributes[$field])) {
                $attributes[$field] = strtoupper(str_replace(' ', '', (string) $attributes[$field]));
            }
        }

        return DB::transaction(fn (): Supplier => CurrentCompany::run($company, function () use ($attributes, $supplier, $company, $user): Supplier {
            $attributes['default_account_id'] = null;

            if (isset($attributes['default_account'])) {
                $attributes['default_account_id'] = Account::query()->where('number', $attributes['default_account'])->where('is_active', true)->value('id')
                    ?? throw ValidationException::withMessages(['default_account' => __('Konto :number finns inte i kontoplanen.', ['number' => $attributes['default_account']])]);
            }

            unset($attributes['default_account']);

            if ($supplier === null) {
                $supplier = Supplier::create([...$attributes, 'number' => NumberSeries::take(NumberSeries::SUPPLIERS)]);

                Audit::record('supplier.created', $supplier, ['number' => $supplier->number, 'name' => $supplier->name], $company, $user);

                return $supplier;
            }

            $before = $supplier->only(array_keys($attributes));
            $supplier->update($attributes);

            Audit::record('supplier.updated', $supplier, Audit::changes($before, $supplier->only(array_keys($attributes))), $company, $user);

            return $supplier;
        }));
    }
}
