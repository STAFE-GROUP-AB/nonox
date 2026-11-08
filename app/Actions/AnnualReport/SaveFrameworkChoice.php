<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Actions\Company\ChangeReportingFramework;
use App\Domain\AnnualReport\K3Eligibility;
use App\Domain\AnnualReport\K3EligibilityAnswers;
use App\Domain\AnnualReport\K3EligibilityQuestion;
use App\Domain\AnnualReport\K3Policies;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\Company\ReportingFramework;
use App\Models\ClosingCheck;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves Bokslut → Regelverk for one fiscal year (ADR-059, addendum
 * 2026-11-07, S1-P11) in one transaction, through the existing actions:
 *
 * 1. the person's answers to the K2 1.1–1.1C questions, as `closing_checks`
 *    rows (fiscal-years:manage, open year only);
 * 2. the framework, through ChangeReportingFramework (company:update; it
 *    refuses K2 when K3Eligibility says K3 is required);
 * 3. the K3 accounting policies, through SetAccountingPolicy, and only for
 *    the first K3 year: a later K3 year inherits them (ADR-059 A.3a).
 *
 * Nothing is decided here that the domain classes do not decide.
 */
final class SaveFrameworkChoice
{
    public function __construct(
        private readonly ChangeReportingFramework $changeFramework,
        private readonly SetAccountingPolicy $setPolicy,
    ) {}

    /**
     * @param  array{framework?: ?string, reason?: ?string, framework_confirmed?: bool, answers?: array<string, ?string>, building_classified?: bool, building_accounts?: list<int|string>, policies?: array<string, ?string>}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, int $fiscalYearId, array $input): FiscalYear
    {
        return CurrentCompany::run($company, fn (): FiscalYear => DB::transaction(function () use ($user, $company, $fiscalYearId, $input): FiscalYear {
            // Inside the company: another company's fiscal year answers 404.
            $year = FiscalYear::query()->findOrFail($fiscalYearId);

            if (array_key_exists('answers', $input) || array_key_exists('building_classified', $input)) {
                $this->saveAnswers($user, $company, $year, self::answers($input));
            }

            $framework = ReportingFramework::tryFrom((string) ($input['framework'] ?? ''));

            if ($framework !== null && $framework !== $year->reporting_framework) {
                ($this->changeFramework)($user, $year, $framework, $input['reason'] ?? null, ['framework_confirmed' => (bool) ($input['framework_confirmed'] ?? false)]);
                $year->refresh();
            }

            $this->savePolicies($user, $company, $year, $input['policies'] ?? []);

            return $year;
        }));
    }

    /**
     * Whether the year is the company's first K3 year: K3, and the year
     * before it is not K3 or not in NONOX. Policies are written from it only.
     */
    public static function isFirstK3Year(FiscalYear $year, ?FiscalYear $previous, ?ReportingFramework $framework = null): bool
    {
        return ($framework ?? $year->reporting_framework) === ReportingFramework::K3
            && ($previous === null || $previous->reporting_framework !== ReportingFramework::K3);
    }

    /** @param array{answers?: array<string, ?string>, building_classified?: bool, building_accounts?: list<int|string>} $input */
    public static function answers(array $input): K3EligibilityAnswers
    {
        $answers = [];

        foreach ($input['answers'] ?? [] as $key => $value) {
            $question = K3EligibilityQuestion::tryFrom((string) $key);

            if ($question === null) {
                throw ValidationException::withMessages(['answers' => __('Okänd fråga.')]);
            }

            if ($value === 'yes' || $value === 'no') {
                $answers[$question->value] = $value === 'yes';
            }
        }

        $accounts = [];

        foreach ($input['building_accounts'] ?? [] as $number) {
            if (! is_numeric($number) || (int) $number < 3000 || (int) $number > 3799) {
                throw ValidationException::withMessages(['building_accounts' => __('Bara intäktskonton för nettoomsättning (3000–3799) kan klassificeras.')]);
            }

            $accounts[] = (int) $number;
        }

        $classified = (bool) ($input['building_classified'] ?? false);
        $accounts = array_values(array_unique($accounts));
        sort($accounts);

        return new K3EligibilityAnswers($answers, $classified ? $accounts : [], $classified);
    }

    private function saveAnswers(User $user, Company $company, FiscalYear $year, K3EligibilityAnswers $answers): void
    {
        // One writer per year: two saves cannot both read the same "before" and insert both a yes and a no.
        FiscalYear::query()->whereKey($year->id)->lockForUpdate()->firstOrFail();

        $before = K3EligibilityAnswers::forYear($year)->keys();
        $after = self::askedOnly($year, $answers)->keys();

        if ($before === $after) {
            return;
        }

        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        if (! $year->isOpen()) {
            throw ValidationException::withMessages(['answers' => __('Räkenskapsåret :year är stängt. Svaren kan inte ändras.', ['year' => $year->label()])]);
        }

        $removed = array_values(array_diff($before, $after));
        $added = array_values(array_diff($after, $before));

        // A question has one answer: remove both "<question>.yes" and "<question>.no" before the new one is inserted.
        $replaced = [];

        foreach ($added as $key) {
            $parsed = K3EligibilityQuestion::parse($key);

            if ($parsed !== null) {
                $replaced[] = $parsed[0]->key(true);
                $replaced[] = $parsed[0]->key(false);
            }
        }

        K3EligibilityAnswers::query($year)->whereIn('key', array_values(array_unique([...$removed, ...$replaced])))->delete();

        foreach ($added as $key) {
            ClosingCheck::query()->create(['fiscal_year_id' => $year->id, 'key' => $key, 'checked_by' => $user->id]);
        }

        // Keys are fixed codes and account numbers, never free text.
        Audit::record('k3_eligibility.answered', $year, [
            'fiscal_year_id' => $year->id,
            'added' => $added,
            'removed' => $removed,
        ], $company, $user);
    }

    /**
     * The answers to questions the screen actually asks for the year, given
     * those answers; an answer to a question that is not asked (for example
     * 1.1B when the 1.1C exception applies) is not stored.
     */
    private static function askedOnly(FiscalYear $year, K3EligibilityAnswers $answers): K3EligibilityAnswers
    {
        $result = K3Eligibility::forYear($year, $answers);
        $asked = array_map(fn (K3EligibilityQuestion $question): string => $question->value, $result->questions());
        $kept = array_intersect_key($answers->answers, array_flip($asked));
        $building = $result->building !== null && $answers->buildingClassified;

        return new K3EligibilityAnswers($kept, $building ? $answers->buildingAccounts : [], $building);
    }

    /** @param array<string, ?string> $policies */
    private function savePolicies(User $user, Company $company, FiscalYear $year, array $policies): void
    {
        $inForce = K3Policies::forYear($year);
        $changed = [];

        foreach ($policies as $key => $value) {
            $policyKey = K3PolicyKey::tryFrom((string) $key);

            if ($policyKey === null) {
                throw ValidationException::withMessages(['policies' => __('Okänd princip.')]);
            }

            if (filled($value) && $inForce->row($policyKey)?->value !== $value) {
                $changed[] = [$policyKey, (string) $value];
            }
        }

        if ($changed === []) {
            return;
        }

        $previous = FiscalYear::query()->whereDate('end_date', $year->start_date->subDay()->toDateString())->first();

        if (! self::isFirstK3Year($year, $previous)) {
            throw ValidationException::withMessages(['policies' => $year->reporting_framework === ReportingFramework::K3
                ? __('Principerna sparas från det första räkenskapsåret med K3 och gäller sedan för de följande åren. Räkenskapsåret :year är inte det första K3-året.', ['year' => $year->label()])
                : __('Principerna gäller bara K3. Välj K3 för räkenskapsåret :year först.', ['year' => $year->label()]),
            ]);
        }

        foreach ($changed as [$policyKey, $value]) {
            ($this->setPolicy)($user, $company, $year->id, $policyKey, $value);
        }
    }
}
