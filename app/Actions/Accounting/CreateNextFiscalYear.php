<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\FiscalYearRules;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Notifications\FiscalYearCreated;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;

/**
 * Creates the fiscal year that follows the latest one, so the years never
 * overlap or leave a gap. Normally twelve months; a company that must use
 * the calendar year moves to it (FiscalYearRules::nextYear).
 *
 * Three ways in (ADR-010, "Automatic next fiscal year"):
 *
 * - by hand (__invoke), from Räkenskapsår;
 * - every morning (dueFor, from fiscal-years:create-next) when the latest
 *   year ends within DAYS_AHEAD days or ended less than a year ago;
 * - while booking (covering, from SaveDraftVoucher) when a voucher is dated
 *   in the year right after the latest one, at most DAYS_AHEAD days ahead.
 *
 * The two automatic ways only create an ordinary year: twelve months, the
 * day after the latest year, starting in the company's first month
 * (fiscal_year_start_month, January for a sole trader). A shortened or
 * extended year (omläggning, BFL 3 kap. 3 §) stays a decision for a person.
 * None of them closes the old year or carries balances forward.
 *
 * Every creation holds a transaction-level advisory lock per company
 * (LOCK, company id) and reads the latest year under it, so two runs never
 * create the same year twice.
 */
class CreateNextFiscalYear
{
    /** The next year is created this many days before the latest one ends. */
    public const int DAYS_AHEAD = 30;

    /** First key of the advisory lock; the second is the company id. FixedAssets uses 4201. */
    public const int LOCK = 4202;

    public function __invoke(User $user, Company $company): FiscalYear
    {
        Gate::forUser($user)->authorize('create', [FiscalYear::class, $company]);

        return CurrentCompany::run($company, fn (): FiscalYear => DB::transaction(function () use ($company, $user): FiscalYear {
            self::lock($company->id);
            $latest = FiscalYear::query()->orderByDesc('end_date')->first();

            if (! $latest instanceof FiscalYear) {
                throw ValidationException::withMessages([
                    'fiscal_year' => __('Företaget har inget räkenskapsår ännu.'),
                ]);
            }

            if ($latest->start_date->isAfter(CarbonImmutable::today())) {
                throw ValidationException::withMessages([
                    'fiscal_year' => __('Nästa räkenskapsår, :label, finns redan. Det året efter kan skapas när :label har börjat.', ['label' => $latest->label()]),
                ]);
            }

            [$start, $end] = FiscalYearRules::nextYear($latest->end_date->addDay(), FiscalYearRules::requiresCalendarYear($company->legal_form));

            return $this->create($company, $start, $end, $user, []);
        }));
    }

    /**
     * The scheduled run for one company: creates the next ordinary year when
     * the latest one ends within DAYS_AHEAD days, or has ended and today
     * falls in the year that follows. Returns null when nothing is due, the
     * year exists, the company is archived, or the next year would not be
     * an ordinary one.
     */
    public function dueFor(Company $company, CarbonImmutable $today): ?FiscalYear
    {
        if ($company->isArchived()) {
            return null;
        }

        return CurrentCompany::run($company, fn (): ?FiscalYear => DB::transaction(function () use ($company, $today): ?FiscalYear {
            self::lock($company->id);
            $next = $this->ordinaryNextYear($company);

            if ($next === null) {
                return null;
            }

            [$start, $end] = $next;

            // The latest year ends more than DAYS_AHEAD days from today, or so long ago that today is past the next year too.
            if ($start->subDay()->isAfter($today->addDays(self::DAYS_AHEAD)) || $end->isBefore($today)) {
                return null;
            }

            $fiscalYear = $this->create($company, $start, $end, null, ['via' => 'automatic', 'trigger' => 'schedule']);
            $this->notify($company, $fiscalYear);

            return $fiscalYear;
        }));
    }

    /**
     * The safety net while booking: when no year covers $date, creates the
     * ordinary year right after the latest one if $date falls in it and is
     * at most DAYS_AHEAD days after today. Returns null otherwise, and the
     * caller keeps its refusal. Runs inside the caller's transaction.
     */
    public function covering(Company $company, CarbonImmutable $date, CarbonImmutable $today, User $user): ?FiscalYear
    {
        if ($company->isArchived() || $date->isAfter($today->addDays(self::DAYS_AHEAD))) {
            return null;
        }

        return CurrentCompany::run($company, fn (): ?FiscalYear => DB::transaction(function () use ($company, $date, $user): ?FiscalYear {
            self::lock($company->id);

            // Another booking may have created it while this one waited for the lock.
            $existing = FiscalYear::query()->where('start_date', '<=', $date)->where('end_date', '>=', $date)->first();

            if ($existing instanceof FiscalYear) {
                return $existing;
            }

            $next = $this->ordinaryNextYear($company);

            if ($next === null || ! $date->betweenIncluded($next[0], $next[1])) {
                return null;
            }

            $fiscalYear = $this->create($company, $next[0], $next[1], $user, ['via' => 'automatic', 'trigger' => 'booking']);
            $this->notify($company, $fiscalYear);

            return $fiscalYear;
        }));
    }

    /**
     * Serialises every creation of a fiscal year in the company until the
     * transaction ends. Also taken by CarryForwardBalances.
     */
    public static function lock(int $companyId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK, $companyId]);
    }

    /**
     * The year after the latest one when it is an ordinary year: twelve
     * months starting in the company's first month. Null when there is no
     * year yet, or when the next year would be a change of fiscal year.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function ordinaryNextYear(Company $company): ?array
    {
        $latest = FiscalYear::query()->orderByDesc('end_date')->first();

        if (! $latest instanceof FiscalYear) {
            return null;
        }

        $calendarYear = FiscalYearRules::requiresCalendarYear($company->legal_form);
        [$start, $end] = FiscalYearRules::nextYear($latest->end_date->addDay(), $calendarYear);
        $startMonth = $calendarYear ? 1 : $company->fiscal_year_start_month;

        if ($start->day !== 1 || $start->month !== $startMonth || FiscalYearRules::months($start, $end) !== FiscalYearRules::NORMAL_MONTHS) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * @param  array<string, string>  $context
     */
    private function create(Company $company, CarbonImmutable $start, CarbonImmutable $end, ?User $user, array $context): FiscalYear
    {
        $fiscalYear = FiscalYear::create(['start_date' => $start, 'end_date' => $end]);

        Audit::record('fiscal_year.created', $fiscalYear, [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            ...$context,
        ], $company, $user);

        return $fiscalYear;
    }

    /**
     * Tells the owner and everyone who may manage fiscal years, once per
     * person and year: a notice that already exists is not sent again.
     */
    private function notify(Company $company, FiscalYear $fiscalYear): void
    {
        $roles = array_values(array_filter(
            array_keys(Jetstream::$roles),
            fn (string $role): bool => in_array('fiscal-years:manage', Jetstream::findRole($role)->permissions ?? [], true),
        ));

        $ids = DB::table('team_user')->where('team_id', $company->id)->whereIn('role', $roles)->pluck('user_id')
            ->push($company->user_id)->map(fn (mixed $id): int => (int) $id)->unique()->values();

        $told = DB::table('notifications')
            ->where('type', FiscalYearCreated::class)
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $ids)
            ->where('data->fiscal_year_id', $fiscalYear->id)
            ->pluck('notifiable_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $notice = FiscalYearCreated::for($company, $fiscalYear);

        foreach (User::query()->whereIn('id', $ids->diff($told))->get() as $user) {
            $user->notify($notice);
        }
    }
}
