<?php

declare(strict_types=1);

namespace App\Actions\Mileage;

use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Mileage\Distance;
use App\Domain\Mileage\MileageLog;
use App\Domain\Mileage\TripKind;
use App\Domain\Mileage\TripStatus;
use App\Domain\Mileage\VehicleKind;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Models\VehicleTrip;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\PayrollRuleVerification;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Support\Audit\Audit;
use App\Support\Mileage\TripListPdf;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner of an enskild firma books a month's trips in their own car
 * (ADR-072 §7, KJ-R15, F7). The deduction is 2,50 kr per km under
 * IL 16 kap. 27 §, the same amount as the payroll rule for an employee's
 * own car, and none for a car that is an asset of the business (F7b).
 *
 * "Bokför" builds a DRAFT voucher in series A on the month's last day:
 * debit the cost account the owner picks, credit 2018 Övriga egna
 * insättningar (the owner paid the car privately), no input VAT (KJ-R17),
 * with the trip list as a PDF. The owner posts it through the normal flow;
 * the trips become BOOKED when it is posted (VoucherPosted). Deleting the
 * draft frees the trips again.
 *
 * TODO(legal-verify): L8/L9 — which BAS account (5800 is a proposal), monthly
 * or per trip, and whether booking is better than claiming the deduction
 * on the NE-bilaga instead.
 */
class BookOwnerMileage
{
    /** The proposal; the owner chooses. TODO(legal-verify): L9. */
    public const int DEFAULT_COST_ACCOUNT = 5800;

    /** Övriga egna insättningar: the owner paid the car privately. TODO(legal-verify): L9. */
    public const int OWNER_DEPOSITS = 2018;

    public const string RULE = 'mileage.own_car_tax_free_per_mil';

    public function __construct(private readonly SaveDraftVoucher $draft) {}

    public function __invoke(User $user, Company $company, string $month, int $costAccount = self::DEFAULT_COST_ACCOUNT): Voucher
    {
        $this->authorize($user, $company);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => __('Välj en månad.')]);
        }

        $monthStart = CarbonImmutable::parse("{$month}-01");

        return CurrentCompany::run($company, fn (): Voucher => DB::transaction(function () use ($user, $company, $monthStart, $costAccount): Voucher {
            $trips = VehicleTrip::query()->with('vehicle')
                ->where('driver_user_id', $user->id)
                ->where('kind', TripKind::Business->value)
                ->whereIn('status', [TripStatus::Draft->value, TripStatus::Rejected->value])
                ->whereNull('voucher_id')
                ->whereDate('trip_date', '<=', $monthStart->endOfMonth()->toDateString())
                ->where(fn ($query) => $query->whereDate('trip_date', '>=', $monthStart->toDateString())->orWhereNotNull('corrects_trip_id'))
                ->orderBy('trip_date')
                ->lockForUpdate()
                ->get()
                ->filter(fn (VehicleTrip $trip): bool => $trip->vehicle->kind === VehicleKind::PrivateCar)
                ->values();

            if ($trips->isEmpty()) {
                throw ValidationException::withMessages(['month' => __('Det finns inga tjänsteresor i egen bil att bokföra för :month.', ['month' => SubmitMileageClaim::monthName($monthStart)])]);
            }

            if ($trips->contains(fn (VehicleTrip $trip): bool => $trip->vehicle->is_business_asset)) {
                throw ValidationException::withMessages(['month' => __('En bil som är en tillgång i firman ger inget avdrag med 25 kronor per mil (inkomstskattelagen 16 kap. 27 §). Bilens kostnader bokförs i stället som firmans kostnader.')]);
            }

            if ($costAccount < 4000 || $costAccount > 7999) {
                throw ValidationException::withMessages(['account' => __('Välj ett kostnadskonto mellan 4000 och 7999.')]);
            }

            $account = Account::query()->where('number', $costAccount)->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(['account' => __('Konto :number finns inte i kontoplanen.', ['number' => $costAccount])]);

            if (! Account::query()->where('number', self::OWNER_DEPOSITS)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['account' => __('Konto :number finns inte i kontoplanen. Lägg till det under Kontoplan först.', ['number' => self::OWNER_DEPOSITS])]);
            }

            $amount = self::amountFor($trips);

            if ($amount === 0) {
                throw ValidationException::withMessages(['month' => __('Resorna och rättelserna tar ut varandra; det finns inget att bokföra.')]);
            }

            $hm = (int) $trips->sum('distance_hm');
            $description = __('Bilresor i egen bil :month, :distance', ['month' => SubmitMileageClaim::monthName($monthStart), 'distance' => Distance::formatMil($hm)]);
            // A month of corrections only gives a negative amount: the deduction is taken back, debit 2018 and credit the cost.
            $rows = $amount > 0
                ? [
                    ['account' => $account->number, 'debit_ore' => $amount, 'credit_ore' => 0, 'description' => $description],
                    ['account' => self::OWNER_DEPOSITS, 'debit_ore' => 0, 'credit_ore' => $amount, 'description' => __('Egen bil, betald privat')],
                ]
                : [
                    ['account' => self::OWNER_DEPOSITS, 'debit_ore' => -$amount, 'credit_ore' => 0, 'description' => __('Rättelse av bilresor i egen bil')],
                    ['account' => $account->number, 'debit_ore' => 0, 'credit_ore' => -$amount, 'description' => $description],
                ];
            $voucher = ($this->draft)($user, $company, [
                // Never a date after today (P3): the month's last day, or today while the month is running.
                'voucher_date' => $monthStart->endOfMonth()->startOfDay()->min(CarbonImmutable::today())->toDateString(),
                'description' => $description,
                'series' => 'A',
                'rows' => $rows,
            ]);

            VehicleTrip::query()->whereIn('id', $trips->pluck('id'))->update(['voucher_id' => $voucher->id, 'status' => TripStatus::Draft->value, 'updated_at' => now()]);
            TripListPdf::attach($user, $voucher, $company, __('Körjournal: :what', ['what' => $description]), (string) $user->name, $trips);
            Audit::record('mileage.owner_voucher_drafted', $voucher, ['trips' => $trips->count(), 'distance_hm' => $hm, 'amount_ore' => $amount], $company, $user);

            return $voucher;
        }));
    }

    /**
     * The amount per mil for a year, from the payroll rule (never a
     * constant). A version imported but not checked can be checked here by
     * the owner, who has no payroll module to do it in.
     *
     * @throws ValidationException when the year's amount is missing or not checked
     */
    public static function rateFor(int $year): int
    {
        try {
            return (int) ((array) RuleSet::for(CarbonImmutable::create($year, 12, 31) ?? CarbonImmutable::today())->get(self::RULE))['ore'];
        } catch (MissingPayrollRuleException $exception) {
            throw ValidationException::withMessages(['rate' => $exception->unverified
                ? __('Beloppet per mil för :year är inte kontrollerat. Jämför det med källan och markera det som kontrollerat på sidan Körjournal.', ['year' => $year])
                : __('Beloppet per mil för :year finns inte ännu.', ['year' => $year])]);
        }
    }

    /** The version of the own-car amount for a year that is waiting to be checked, if any. */
    public static function uncheckedRate(int $year): ?PayrollRuleVersion
    {
        try {
            self::rateFor($year);

            return null;
        } catch (ValidationException) {
            return PayrollRuleVersion::query()->where('key', self::RULE)
                ->whereDate('valid_from', '<=', "{$year}-12-31")
                ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', "{$year}-12-31"))
                ->latest('valid_from')
                ->first();
        }
    }

    /** The owner checks the year's amount against its source (PAYROLL_MODULE.md B.6), for the company only. */
    public function verifyRate(User $user, Company $company, PayrollRuleVersion $rule): PayrollRuleVerification
    {
        $this->authorize($user, $company);

        if ($rule->key !== self::RULE) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, function () use ($user, $company, $rule): PayrollRuleVerification {
            $verification = PayrollRuleVerification::query()->firstOrCreate(['payroll_rule_version_id' => $rule->id], [
                'verified_by' => $user->id,
                'verifier_name' => $user->name,
                'verified_at' => now(),
            ]);
            Audit::record('payroll.rule_verified', $verification, ['key' => $rule->key, 'valid_from' => $rule->valid_from->toDateString(), 'value' => $rule->value, 'source_url' => $rule->source_url], $company, $user);

            return $verification;
        });
    }

    /** After the owner posts the draft, its trips are booked and frozen. */
    public static function markBooked(int $companyId, string $voucherId): void
    {
        CurrentCompany::run($companyId, function () use ($companyId, $voucherId): void {
            $trips = VehicleTrip::query()->where('voucher_id', $voucherId)->where('status', TripStatus::Draft->value)->get();

            if ($trips->isEmpty()) {
                return;
            }

            VehicleTrip::query()->whereKey($trips->pluck('id'))->update(['status' => TripStatus::Booked->value, 'updated_at' => now()]);

            // The person may have changed the amount in the draft before posting. The posting stands (it is the person's),
            // but the difference is written down and shown on the page (P3: warn, not refuse).
            $posted = self::postedAmount($voucherId);
            $expected = rescue(fn (): int => self::amountFor($trips), null, false);

            if ($expected !== null && $posted !== $expected) {
                Audit::record('mileage.owner_voucher_amount_differs', Voucher::query()->find($voucherId), ['expected_ore' => $expected, 'posted_ore' => $posted], $companyId);
            }
        });
    }

    /**
     * The deduction for the trips at each trip year's amount per mil, rounded once per year.
     *
     * @param  Collection<int, VehicleTrip>  $trips
     */
    public static function amountFor(Collection $trips): int
    {
        $amount = 0;

        foreach ($trips->groupBy(fn (VehicleTrip $trip): int => $trip->trip_date->year) as $year => $yearTrips) {
            $amount += Distance::amountOre((int) $yearTrips->sum('distance_hm'), self::rateFor((int) $year));
        }

        return $amount;
    }

    /** What a posted voucher puts on 2018, as credit minus debit: the deduction booked. */
    public static function postedAmount(string $voucherId): int
    {
        return (int) VoucherRow::query()->where('voucher_id', $voucherId)
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('accounts.number', self::OWNER_DEPOSITS)
            ->sum(DB::raw('voucher_rows.credit_ore - voucher_rows.debit_ore'));
    }

    /**
     * The booked vouchers among the trips whose 2018 amount differs from the trips' deduction, for a warning on the page.
     *
     * @param  Collection<int, VehicleTrip>  $trips
     * @return list<string> voucher ids
     */
    public static function differing(Collection $trips): array
    {
        $differing = [];

        foreach ($trips->filter(fn (VehicleTrip $trip): bool => $trip->status === TripStatus::Booked && $trip->voucher_id !== null)->groupBy('voucher_id') as $voucherId => $booked) {
            $expected = rescue(fn (): int => self::amountFor($booked->values()), null, false);

            if ($expected !== null && self::postedAmount((string) $voucherId) !== $expected) {
                $differing[] = (string) $voucherId;
            }
        }

        return $differing;
    }

    private function authorize(User $user, Company $company): void
    {
        abort_unless(MileageLog::enabled($company), 404);

        if (! MileageLog::isSoleTraderOwner($user, $company)) {
            throw new AuthorizationException;
        }
    }
}
