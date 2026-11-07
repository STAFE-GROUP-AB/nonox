<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Domain\AnnualReport\K3FinancialInstruments;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\CompanyAccountingPolicy;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Records a K3 accounting policy from the start of a fiscal year (ADR-059,
 * addendum 2026-11-07, A.2.3). Prepared by whoever prepares the annual report
 * (fiscal-years:manage: owner, administrator, accountant). Refused from a year
 * whose annual report is adopted, or from an earlier start that would reach
 * it: a change of principle under K3 chapter 10 is a later stage. An
 * unsupported value is stored and listed by K3Policies::refusals(), so the
 * report can say why it is refused.
 */
final class SetAccountingPolicy
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, int $fiscalYearId, K3PolicyKey $key, string $value, ?string $reason = null): CompanyAccountingPolicy
    {
        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYearId, $key, $value, $reason): CompanyAccountingPolicy {
            // Inside the company: another company's fiscal year answers 404.
            $fiscalYear = FiscalYear::query()->findOrFail($fiscalYearId);

            Validator::make(['value' => $value, 'reason' => $reason], [
                'value' => ['required', 'string', Rule::in($key->values())],
                'reason' => ['nullable', 'string', 'max:2000'],
            ], [], [
                'value' => __('principen'),
                'reason' => __('motiveringen'),
            ])->validate();

            $reason = blank($reason) ? null : trim($reason);
            $from = $fiscalYear->start_date->toDateString();

            return DB::transaction(function () use ($user, $company, $fiscalYear, $key, $value, $reason, $from): CompanyAccountingPolicy {
                $policy = CompanyAccountingPolicy::query()
                    ->where('key', $key->value)
                    ->where('applies_from', $from)
                    ->lockForUpdate()
                    ->first();

                if ($policy !== null && $policy->value === $value && $policy->reason === $reason) {
                    return $policy;
                }

                $this->assertNotAdopted($fiscalYear);

                if ($key === K3PolicyKey::FinancialInstruments) {
                    $this->assertNoSwitchBackToChapter11($from, $value);
                }

                $before = $policy?->value;
                $policy ??= new CompanyAccountingPolicy([
                    'company_id' => $company->id,
                    'key' => $key,
                    'applies_from' => $from,
                ]);
                $policy->fill(['value' => $value, 'reason' => $reason, 'set_by' => $user->id])->save();

                // Ids and enums only: the reason is free text and stays in its column.
                Audit::record('accounting_policy.set', $policy, [
                    'key' => $key->value,
                    'before' => $before,
                    'after' => $value,
                    'fiscal_year_id' => $fiscalYear->id,
                    'reason_given' => $reason !== null,
                ], $company, $user);

                return $policy;
            });
        });
    }

    /** No policy may change for a year whose annual report the meeting has adopted. */
    private function assertNotAdopted(FiscalYear $fiscalYear): void
    {
        $adopted = AnnualReport::query()
            ->whereNotNull('adoption_date')
            ->whereIn('fiscal_year_id', FiscalYear::query()->where('end_date', '>=', $fiscalYear->start_date->toDateString())->select('id'))
            ->exists();

        if (! $adopted) {
            return;
        }

        throw ValidationException::withMessages([
            'value' => __('Principen kan inte ändras från räkenskapsåret som börjar :date, eftersom en årsredovisning från och med det året redan är fastställd. Byte av redovisningsprincip (K3 kapitel 10) stöds inte ännu.', ['date' => $fiscalYear->start_date->toDateString()]),
        ]);
    }

    /**
     * S1-R6, K3 11.3 and 12.3: chapter 11 to 12 is allowed, chapter 12 back to
     * 11 only under K3 10.2 c or d, which NONOX cannot judge and does not offer.
     * Checked against the row in force before and the next row after.
     */
    private function assertNoSwitchBackToChapter11(string $from, string $value): void
    {
        $rows = CompanyAccountingPolicy::query()->where('key', K3PolicyKey::FinancialInstruments->value);
        $previous = (clone $rows)->where('applies_from', '<', $from)->orderByDesc('applies_from')->value('value');
        $next = (clone $rows)->where('applies_from', '>', $from)->orderBy('applies_from')->value('value');

        $chapter11 = K3FinancialInstruments::Chapter11->value;
        $chapter12 = K3FinancialInstruments::Chapter12->value;

        if (! ($previous === $chapter12 && $value === $chapter11) && ! ($value === $chapter12 && $next === $chapter11)) {
            return;
        }

        throw ValidationException::withMessages([
            'value' => __('Ett företag som tillämpar K3 kapitel 12 får byta till kapitel 11 bara i de fall som anges i K3 10.2 c eller d. Det bytet stöds inte i NONOX.'),
        ]);
    }
}
