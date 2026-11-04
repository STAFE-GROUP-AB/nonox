<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Models\Company;
use App\Models\RegistryCompany;
use App\Models\RegistryImport;
use App\Support\CompanyRegistry\Import\BolagsverketBulkFile;
use App\Support\CompanyRegistry\Import\ScbApi;
use App\Support\CompanyRegistry\Import\ScbBulkFile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The prospect search over the instance's company register (ADR-047). The
 * register is public data and the same for every company; the only
 * company-specific part is "not already in our CRM", which reads the
 * company's own accounts with an explicit company_id.
 *
 * Filters: search (a name contains it, case-insensitive; or, when it is
 * digits with or without a hyphen, an organisation number starts with it;
 * at least two characters), sni (five-digit code or two-digit division), town, postal (a
 * prefix of the postal code), county, municipality, form (SCB legal form),
 * from/to (registration date), employees_min/max and turnover_min/max
 * (size classes), and the switches all (include companies not in
 * business), blocked (include postal advertising opt-outs) and new (only
 * companies not in the CRM).
 */
final class ProspectSearch
{
    /** The most companies added or put on a call list from one search. */
    public const int MAX_ADD = 500;

    /** The filters that narrow a search; a search needs at least one. */
    public const array NARROWING = ['sni', 'town', 'postal', 'county', 'municipality', 'form', 'from', 'to', 'employees_min', 'employees_max', 'turnover_min', 'turnover_max'];

    /** The shortest name search: one character would match most of the register. */
    public const int MIN_SEARCH = 2;

    /** @param  array<string, mixed>  $filters */
    public static function hasFilter(array $filters): bool
    {
        if (self::searchTerm((string) ($filters['search'] ?? '')) !== null) {
            return true;
        }

        foreach (self::NARROWING as $key) {
            if (trim((string) ($filters[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<RegistryCompany>
     */
    public static function query(array $filters, Company $company): Builder
    {
        $text = fn (string $key): string => trim((string) ($filters[$key] ?? ''));
        $sni = (string) preg_replace('/\D/', '', $text('sni'));
        $int = fn (string $key): ?int => is_numeric($filters[$key] ?? null) ? (int) $filters[$key] : null;

        $query = RegistryCompany::query()
            ->when(! (bool) ($filters['all'] ?? false), fn (Builder $query) => $query->inBusiness())
            ->when(! (bool) ($filters['blocked'] ?? false), fn (Builder $query) => $query->where('advertising_block', false))
            ->when(strlen($sni) === 5, fn (Builder $query) => $query->where('sni_code', $sni))
            ->when(strlen($sni) === 2, fn (Builder $query) => $query->where('sni_division', $sni))
            ->when($text('town') !== '', fn (Builder $query) => $query->where('city', mb_strtoupper($text('town'))))
            ->when(preg_match('/^\d{1,5}$/', (string) preg_replace('/\s/', '', $text('postal'))) === 1, fn (Builder $query) => $query->where('postal_code', 'like', preg_replace('/\s/', '', $text('postal')).'%'))
            ->when($text('county') !== '', fn (Builder $query) => $query->where('county_code', $text('county')))
            ->when($text('municipality') !== '', fn (Builder $query) => $query->where('municipality_code', $text('municipality')))
            ->when($text('form') !== '', fn (Builder $query) => $query->where('legal_form', $text('form')))
            ->when(self::date($text('from')) !== null, fn (Builder $query) => $query->where('registered_on', '>=', self::date($text('from'))))
            ->when(self::date($text('to')) !== null, fn (Builder $query) => $query->where('registered_on', '<=', self::date($text('to'))))
            ->when($int('employees_min') !== null, fn (Builder $query) => $query->where('employee_class', '>=', $int('employees_min')))
            ->when($int('employees_max') !== null, fn (Builder $query) => $query->where('employee_class', '<=', $int('employees_max')))
            ->when($int('turnover_min') !== null, fn (Builder $query) => $query->where('turnover_class', '>=', $int('turnover_min')))
            ->when($int('turnover_max') !== null, fn (Builder $query) => $query->where('turnover_class', '<=', $int('turnover_max')));

        self::matching($query, (string) ($filters['search'] ?? ''));

        if ((bool) ($filters['new'] ?? true)) {
            self::notInCrm($query, $company);
        }

        return $query;
    }

    /**
     * The free-text search: the name contains the term (ILIKE, answered by
     * the trigram index company_registry_name_trgm_index), or, for a term of
     * digits with or without a hyphen, the organisation number starts with
     * it (a range on the unique index, whatever the collation). LIKE's
     * wildcards in the term are matched literally. A term shorter than
     * MIN_SEARCH characters is ignored.
     *
     * @param  Builder<RegistryCompany>  $query
     */
    public static function matching(Builder $query, string $search): void
    {
        $term = self::searchTerm($search);

        if ($term === null) {
            return;
        }

        $digits = preg_match('/^[\d\s-]+$/', $term) === 1 ? (string) preg_replace('/\D/', '', $term) : '';
        $pattern = '%'.addcslashes($term, '\\%_').'%';

        $query->where(function (Builder $any) use ($pattern, $digits): void {
            $any->where('name', 'ilike', $pattern);

            if ($digits !== '' && strlen($digits) <= 10) {
                $any->orWhereBetween('org_number', [str_pad($digits, 10, '0'), str_pad($digits, 10, '9')]);
            }
        });
    }

    /** The search term, trimmed, or null when it is too short to search on. */
    public static function searchTerm(string $search): ?string
    {
        $term = trim((string) preg_replace('/\s+/u', ' ', $search));

        return mb_strlen($term) >= self::MIN_SEARCH ? mb_substr($term, 0, 100) : null;
    }

    /**
     * Leaves out companies the company already has as an account. Reads
     * crm_accounts directly with the company's id, since the register has
     * no company scope of its own.
     *
     * @param  Builder<RegistryCompany>  $query
     */
    public static function notInCrm(Builder $query, Company $company): void
    {
        $query->whereNotExists(fn (QueryBuilder $accounts) => $accounts->select(DB::raw(1))
            ->from('crm_accounts')
            ->where('crm_accounts.company_id', $company->id)
            ->whereNotNull('crm_accounts.org_number')
            ->whereRaw("regexp_replace(crm_accounts.org_number, '\\D', '', 'g') = company_registry.org_number"));
    }

    /**
     * What the register holds on this instance: whether it is loaded, the
     * date of the data per source, and whether SCB's API has filled the
     * size classes and regions. One query.
     *
     * @return array{loaded: bool, api: bool, dates: array<string, CarbonImmutable>}
     */
    public static function state(): array
    {
        $dates = RegistryImport::query()
            ->where('status', RegistryImport::DONE)
            ->groupBy('source')
            ->selectRaw('source, MAX(finished_at) AS finished')
            ->pluck('finished', 'source')
            ->map(fn (mixed $date): CarbonImmutable => CarbonImmutable::parse((string) $date))
            ->all();

        return [
            'loaded' => isset($dates[ScbBulkFile::KEY]) || isset($dates[ScbApi::KEY]) || isset($dates[BolagsverketBulkFile::KEY]),
            'api' => isset($dates[ScbApi::KEY]),
            'dates' => $dates,
        ];
    }

    private static function date(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
