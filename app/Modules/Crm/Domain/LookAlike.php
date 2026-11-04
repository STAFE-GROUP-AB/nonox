<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\RegistryCompany;
use App\Support\CompanyRegistry\RegistryCodes;
use App\Support\CompanyRegistry\Sni;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Look-alike search without AI (ADR-047): companies in the register that
 * share the industry, size and region of the company's best customers.
 *
 * - The best customers are the CRM's company customers by invoiced value
 *   in the last 12 months (issued invoices excluding VAT, credit invoices
 *   deducted, as AccountFigures counts them).
 * - Each chosen customer found in the register is a profile: its main SNI
 *   code (all five digits, or only the two-digit division), its employee
 *   size class (one class up or down), and its county, or its town when the
 *   register has no counties (no SCB API key).
 * - A register company matches a profile when it meets every criterion
 *   ticked; the result is every company that matches any profile, in
 *   business, not already in the CRM.
 */
final class LookAlike
{
    public const string SNI_CODE = 'code';

    public const string SNI_DIVISION = 'division';

    /** How many best customers are offered, and how many are chosen by default. */
    public const int CANDIDATES = 20;

    public const int DEFAULT_CHOSEN = 5;

    /**
     * The best customers: account id, name, organisation number and the
     * invoiced value of the last 12 months, highest first. One query; in
     * the current company.
     *
     * @return Collection<int, object{account_id: int, name: string, org_number: ?string, invoiced: int}>
     */
    public function bestCustomers(?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::today();
        $from = $today->subYearNoOverflow()->addDay()->toDateString();

        /** @var Collection<int, object{account_id: int, name: string, org_number: ?string, invoiced: int}> */
        return Invoice::query()
            ->toBase()
            ->join('crm_accounts', fn ($join) => $join->on('crm_accounts.customer_id', '=', 'invoices.customer_id')->on('crm_accounts.company_id', '=', 'invoices.company_id'))
            ->where('invoices.status', '<>', InvoiceStatus::Draft->value)
            ->where('invoices.invoice_date', '>=', $from)
            ->where('crm_accounts.kind', AccountKind::Company->value)
            ->whereNotNull('crm_accounts.org_number')
            ->groupBy('crm_accounts.id', 'crm_accounts.name', 'crm_accounts.org_number')
            ->havingRaw('SUM(CASE WHEN invoices.kind = ? THEN -ABS(invoices.net_ore) ELSE invoices.net_ore END) > 0', [Invoice::CREDIT])
            ->select('crm_accounts.id as account_id', 'crm_accounts.name', 'crm_accounts.org_number')
            ->selectRaw('SUM(CASE WHEN invoices.kind = ? THEN -ABS(invoices.net_ore) ELSE invoices.net_ore END) AS invoiced', [Invoice::CREDIT])
            ->orderByDesc('invoiced')
            ->orderBy('crm_accounts.name')
            ->limit(self::CANDIDATES)
            ->get()
            ->map(function (object $row): object {
                $row->account_id = (int) $row->account_id;
                $row->invoiced = (int) $row->invoiced;

                return $row;
            });
    }

    /**
     * The register rows of the chosen customers, keyed by organisation
     * number: the profiles. One query.
     *
     * @param  list<string>  $orgNumbers  however written
     * @return Collection<string, RegistryCompany>
     */
    public function profiles(array $orgNumbers): Collection
    {
        $digits = array_values(array_filter(array_map(fn (string $number): ?string => RegistryCompany::digits($number), $orgNumbers)));

        if ($digits === []) {
            return collect();
        }

        return RegistryCompany::query()->whereIn('org_number', $digits)->get()->keyBy('org_number');
    }

    /**
     * Companies like the profiles.
     *
     * @param  Collection<string, RegistryCompany>  $profiles
     * @param  array{sni: string, size: bool, region: bool, blocked?: bool}  $criteria
     * @return Builder<RegistryCompany>
     */
    public function query(Collection $profiles, array $criteria, Company $company): Builder
    {
        $usable = $profiles->filter(fn (RegistryCompany $profile): bool => $this->conditions($profile, $criteria) !== []);

        $query = RegistryCompany::query()
            ->inBusiness()
            ->when(! ($criteria['blocked'] ?? false), fn (Builder $query) => $query->where('advertising_block', false))
            ->whereNotIn('org_number', $profiles->keys()->all());

        ProspectSearch::notInCrm($query, $company);

        if ($usable->isEmpty()) {
            return $query->whereRaw('false');
        }

        return $query->where(function (Builder $any) use ($usable, $criteria): void {
            foreach ($usable as $profile) {
                $any->orWhere(function (Builder $all) use ($profile, $criteria): void {
                    foreach ($this->conditions($profile, $criteria) as [$column, $operator, $value]) {
                        $operator === 'between'
                            ? $all->whereBetween($column, $value)
                            : $all->where($column, $operator, $value);
                    }
                });
            }
        });
    }

    /**
     * Which profile a hit resembles, and on what, in words.
     *
     * @param  Collection<string, RegistryCompany>  $profiles
     * @param  array{sni: string, size: bool, region: bool, blocked?: bool}  $criteria
     * @param  array<string, string>  $names  org number => the customer's name in the CRM
     * @return ?array{customer: string, reasons: list<string>}
     */
    public function explain(RegistryCompany $hit, Collection $profiles, array $criteria, array $names): ?array
    {
        foreach ($profiles as $org => $profile) {
            $conditions = $this->conditions($profile, $criteria);

            if ($conditions === []) {
                continue;
            }

            $matches = true;

            foreach ($conditions as [$column, $operator, $value]) {
                $actual = $hit->getAttribute($column);
                $matches = $matches && ($operator === 'between'
                    ? $actual !== null && $actual >= $value[0] && $actual <= $value[1]
                    : (string) $actual === (string) $value);
            }

            if ($matches) {
                return ['customer' => $names[$org] ?? $profile->name, 'reasons' => $this->reasons($profile, $criteria)];
            }
        }

        return null;
    }

    /**
     * The conditions one profile sets: [column, operator, value].
     *
     * @param  array{sni: string, size: bool, region: bool, blocked?: bool}  $criteria
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    public function conditions(RegistryCompany $profile, array $criteria): array
    {
        $conditions = [];

        if ($criteria['sni'] === self::SNI_CODE && $profile->sni_code !== null) {
            $conditions[] = ['sni_code', '=', $profile->sni_code];
        } elseif ($criteria['sni'] === self::SNI_DIVISION && $profile->sni_division !== null) {
            $conditions[] = ['sni_division', '=', $profile->sni_division];
        }

        if ($criteria['size'] && $profile->employee_class !== null) {
            $conditions[] = ['employee_class', 'between', [max(1, $profile->employee_class - 1), $profile->employee_class + 1]];
        }

        if ($criteria['region']) {
            if ($profile->county_code !== null) {
                $conditions[] = ['county_code', '=', $profile->county_code];
            } elseif ($profile->city !== null) {
                $conditions[] = ['city', '=', $profile->city];
            }
        }

        return $conditions;
    }

    /**
     * @param  array{sni: string, size: bool, region: bool, blocked?: bool}  $criteria
     * @return list<string>
     */
    private function reasons(RegistryCompany $profile, array $criteria): array
    {
        $reasons = [];

        if ($criteria['sni'] === self::SNI_CODE && $profile->sni_code !== null) {
            $reasons[] = __('samma bransch (:sni)', ['sni' => Sni::label($profile->sni_code)]);
        } elseif ($criteria['sni'] === self::SNI_DIVISION && $profile->sni_division !== null) {
            $reasons[] = __('samma huvudgrupp (:sni)', ['sni' => Sni::label($profile->sni_division)]);
        }

        if ($criteria['size'] && $profile->employee_class !== null) {
            $reasons[] = __('ungefär lika stort (:size)', ['size' => RegistryCodes::employees($profile->employee_class)]);
        }

        if ($criteria['region']) {
            if ($profile->county_code !== null) {
                $reasons[] = __('samma län (:county)', ['county' => RegistryCodes::county($profile->county_code)]);
            } elseif ($profile->city !== null) {
                $reasons[] = __('samma ort (:town)', ['town' => $profile->town()]);
            }
        }

        return $reasons;
    }
}
