<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry;

use App\Models\RegistryCompany;
use App\Models\RegistryExclusion;
use Illuminate\Support\Facades\Cache;

/**
 * NONOX's own company register (company_registry, ADR-047), used to fill
 * in a user's own forms (ADR-035 addendum). One query per lookup. Legal
 * persons only: a personal number (a sole trader's) is never answered
 * here, and a company excluded after an objection or an erasure request
 * (RegistryExclusion ALL) is never returned.
 */
final class LocalRegistry implements CompanyRegistry
{
    public const string KEY = 'register';

    private const string HAS_ROWS_KEY = 'company-registry:local-has-rows';

    public function key(): string
    {
        return self::KEY;
    }

    public function sourceName(): string
    {
        return (string) __('NONOX företagsregister (Bolagsverket och SCB)');
    }

    /** Whether the register has any rows. Only a yes is remembered, so a first import shows at once. */
    public static function hasRows(): bool
    {
        if (Cache::get(self::HAS_ROWS_KEY) === true) {
            return true;
        }

        if (! RegistryCompany::query()->exists()) {
            return false;
        }

        Cache::put(self::HAS_ROWS_KEY, true, now()->addMinutes(10));

        return true;
    }

    public function find(string $identity): array
    {
        if (strlen($identity) !== 10 || (int) $identity[2] < 2) {
            return [];
        }

        $row = RegistryCompany::query()
            ->where('org_number', $identity)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('company_registry_exclusions')
                ->whereColumn('company_registry_exclusions.org_number', 'company_registry.org_number')
                ->where('company_registry_exclusions.field', RegistryExclusion::ALL))
            ->selectRaw('company_registry.*, exists (select 1 from company_registry_exclusions where company_registry_exclusions.org_number = company_registry.org_number and company_registry_exclusions.field = ?) as name_excluded', [RegistryExclusion::NAME])
            ->first();

        if ($row === null) {
            return [];
        }

        $proceeding = RegistryCodes::proceedings($row->proceedings);
        $fetched = collect([$row->fetched_at, $row->bolagsverket_fetched_at])->filter()->max();

        return [new RegisteredCompany(
            name: (bool) $row->getAttribute('name_excluded') ? RegistryExclusion::replacementName($row->org_number) : $row->name,
            legalFormCode: $row->legal_form,
            legalFormText: RegistryCodes::legalForm($row->legal_form),
            street: $row->address_line1,
            coAddress: $row->co_address,
            postalCode: $row->postal_code,
            city: $row->city,
            country: 'Sverige',
            registeredOn: $row->registered_on?->toDateString(),
            deregisteredOn: $row->deregistered_on?->toDateString(),
            proceedings: $proceeding === null ? [] : [$proceeding],
            sourceName: $this->sourceName(),
            sourceDate: $fetched?->toDateString(),
            sniCode: $row->sni_code,
            employeeClass: $row->employee_class,
            turnoverClass: $row->turnover_class,
        )];
    }
}
