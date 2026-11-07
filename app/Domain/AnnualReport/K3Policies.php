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
 * before the year's start. A transition answer (chapter 35) belongs to its own
 * year only and is never carried forward. Read with one query; the report
 * (S1-P10) blocks on missing() and refusals().
 */
final class K3Policies
{
    /**
     * @param  array<string, CompanyAccountingPolicy>  $rows  in force, by key
     * @param  array<string, CompanyAccountingPolicy>  $preceding  the row before the one in force, by key
     */
    private function __construct(
        public readonly FiscalYear $fiscalYear,
        private readonly array $rows,
        private readonly array $preceding,
    ) {}

    public static function forYear(FiscalYear $fiscalYear): self
    {
        $start = $fiscalYear->start_date->toDateString();
        $rows = CurrentCompany::run($fiscalYear->company_id, fn () => CompanyAccountingPolicy::query()
            ->where('applies_from', '<=', $start)
            ->orderByDesc('applies_from')
            ->get());

        $inForce = [];
        $preceding = [];

        foreach ($rows as $row) {
            $key = $row->key->value;

            if ($row->key->transitionOnly() && $row->applies_from->toDateString() !== $start) {
                continue;
            }

            if (! isset($inForce[$key])) {
                $inForce[$key] = $row;

                continue;
            }

            $preceding[$key] ??= $row;
        }

        return new self($fiscalYear, $inForce, $preceding);
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
     * ÅRL 2 kap. 1 §); the caller gives the size from K3CompanySize. A change
     * of principle in the year is listed too (changeOfPrinciple()).
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

            if ($message !== null) {
                $refusals[] = ['key' => $key, 'rule' => $key->rule(), 'message' => $message];
            }

            $change = $this->changeOfPrinciple($key);

            if ($change !== null) {
                $refusals[] = ['key' => $key, ...$change];
            }
        }

        return $refusals;
    }

    /**
     * A policy that differs, in the year it takes effect, from the one in force
     * for the preceding period is a change of principle. K3 10.2 allows it only
     * under a)–d) and with retroactive application (10.4, 10.5), which NONOX does
     * not support yet; from percentage of completion to completed contract K3
     * 23.37 asks for särskilda skäl. The first row of a key is no change.
     *
     * @return ?array{rule: string, message: string}
     */
    private function changeOfPrinciple(K3PolicyKey $key): ?array
    {
        $row = $this->row($key);
        $preceding = $this->preceding[$key->value] ?? null;

        if ($key->transitionOnly() || $row === null || $preceding === null) {
            return null;
        }

        if ($row->applies_from->toDateString() !== $this->fiscalYear->start_date->toDateString()) {
            return null;
        }

        if ($row->value === $preceding->value) {
            return null;
        }

        if ($preceding->policyValue() === K3FixedPriceContracts::PercentageOfCompletion && $row->policyValue() === K3FixedPriceContracts::CompletedContract) {
            return [
                'rule' => 'K3 23.37',
                'message' => (string) __('Ett byte från successiv vinstavräkning till färdigställandemetoden kräver särskilda skäl (K3 23.37). Det bytet stöds inte ännu i NONOX.'),
            ];
        }

        return [
            'rule' => 'K3 10.2',
            'message' => (string) __('Principen ändras från föregående räkenskapsår. Ett byte av redovisningsprincip (K3 10.2) ska tillämpas retroaktivt, och det stöds inte ännu i NONOX.'),
        ];
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
