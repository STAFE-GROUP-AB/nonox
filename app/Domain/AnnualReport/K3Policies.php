<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Models\Company;
use App\Models\CompanyAccountingPolicy;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The K3 accounting policies in force for one fiscal year (ADR-059, addendum
 * 2026-11-07, A.2.3): per key, the row with the latest applies_from on or
 * before the year's start. Read with one query; the report (S1-P10) blocks on
 * missing() and refusals().
 */
final class K3Policies
{
    /** @param array<string, CompanyAccountingPolicy> $rows by key */
    private function __construct(
        public readonly FiscalYear $fiscalYear,
        private readonly array $rows,
    ) {}

    public static function forYear(FiscalYear $fiscalYear): self
    {
        $rows = CurrentCompany::run($fiscalYear->company_id, fn () => CompanyAccountingPolicy::query()
            ->where('applies_from', '<=', $fiscalYear->start_date->toDateString())
            ->orderByDesc('applies_from')
            ->get());

        $inForce = [];

        foreach ($rows as $row) {
            $inForce[$row->key->value] ??= $row;
        }

        return new self($fiscalYear, $inForce);
    }

    /**
     * For a screen or an export: reports:view (the auditor reads, ADR-059 A.5),
     * and another company's fiscal year answers 404.
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException<FiscalYear>
     */
    public static function forUser(User $user, Company $company, int $fiscalYearId): self
    {
        if (! $user->hasTeamPermission($company, 'reports:view')) {
            throw new AuthorizationException;
        }

        $fiscalYear = CurrentCompany::run($company, fn (): FiscalYear => FiscalYear::query()->findOrFail($fiscalYearId));

        return self::forYear($fiscalYear);
    }

    public function row(K3PolicyKey $key): ?CompanyAccountingPolicy
    {
        return $this->rows[$key->value] ?? null;
    }

    public function value(K3PolicyKey $key): ?K3PolicyValue
    {
        return $this->row($key)?->policyValue();
    }

    /**
     * Policies without an answer for the year, in key order. The transition
     * answers (chapter 35) are asked only for a first K3 year after K2.
     *
     * @return list<K3PolicyKey>
     */
    public function missing(bool $transition = false): array
    {
        return array_values(array_filter(
            K3PolicyKey::cases(),
            fn (K3PolicyKey $key): bool => ($transition || ! $key->transitionOnly()) && $this->value($key) === null,
        ));
    }

    /**
     * Policies in force that S1 cannot report on, each with its rule id.
     * `cash_flow_statement` = `none` is refused for a större företag (S1-R2,
     * ÅRL 2 kap. 1 §); the caller gives the size from K3CompanySize.
     *
     * @return list<array{key: K3PolicyKey, rule: string, message: string}>
     */
    public function refusals(bool $largerCompany = false): array
    {
        $refusals = [];

        foreach (K3PolicyKey::cases() as $key) {
            $value = $this->value($key);
            $message = $value?->refusal();

            if ($largerCompany && $value === K3CashFlowStatement::None) {
                $message = (string) __('Ett större företag ska ha en kassaflödesanalys i årsredovisningen (årsredovisningslagen 2 kap. 1 §).');
            }

            if ($message === null) {
                continue;
            }

            $refusals[] = ['key' => $key, 'rule' => $key->rule(), 'message' => $message];
        }

        return $refusals;
    }

    /**
     * S1-R7, R7.6: chapter 35 may be used once (K3 35.1), but without that limit
     * by a company forced off K2 by BFNAR 2025:2. Null while the answers needed
     * are missing.
     */
    public function chapter35Allowed(): ?bool
    {
        $appliedBefore = $this->value(K3PolicyKey::K3AppliedBefore);
        $reason = $this->value(K3PolicyKey::LeftK2Reason);

        if ($appliedBefore === K3YesNo::No || $reason === K3LeftK2Reason::Forced20252) {
            return true;
        }

        if ($appliedBefore === K3YesNo::Yes && $reason === K3LeftK2Reason::Voluntary) {
            return false;
        }

        return null;
    }
}
