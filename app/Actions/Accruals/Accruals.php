<?php

declare(strict_types=1);

namespace App\Actions\Accruals;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Accruals\AccrualKind;
use App\Domain\Accruals\AccrualPlan;
use App\Models\Account;
use App\Models\Accrual;
use App\Models\AccrualPosting;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Periodiseringar: registering accruals and booking a month's steps. A
 * month's run books, on the first day of the month, the reversal of last
 * month's accrued items, and on its last day each prepaid item's share and
 * the month's new accrued items. Part of the closing, so it needs
 * fiscal-years:manage; the vouchers go through BookVoucher and PostVoucher.
 */
class Accruals
{
    public const string SERIES = 'A';

    public function __construct(private readonly BookVoucher $book) {}

    /**
     * @param  array<string, mixed>  $input  name, kind, total, balance_account, result_account, start_month, months, move_from_result, cost_center, notes
     */
    public function save(User $user, Company $company, array $input, ?Accrual $accrual = null): Accrual
    {
        $this->authorize($user, $company);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(AccrualKind::class)],
            'total' => ['required', 'string'],
            'balance_account' => ['required', 'integer', 'between:1000,2999'],
            'result_account' => ['required', 'integer', 'between:3000,8999'],
            'start_month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'months' => ['required', 'integer', 'min:1', 'max:120'],
            'move_from_result' => ['nullable', 'boolean'],
            'cost_center' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'name' => __('benämning'),
            'total' => __('belopp'),
            'balance_account' => __('balanskonto'),
            'result_account' => __('resultatkonto'),
            'start_month' => __('första månad'),
            'months' => __('antal månader'),
        ])->validate();

        $total = $this->money((string) $validated['total']);

        if ($total <= 0) {
            throw ValidationException::withMessages(['total' => __('Beloppet ska vara större än noll.')]);
        }

        $kind = AccrualKind::from((string) $validated['kind']);

        return CurrentCompany::run($company, fn (): Accrual => DB::transaction(function () use ($user, $company, $validated, $total, $kind, $accrual): Accrual {
            if ($accrual !== null && $accrual->postings()->exists()) {
                // The plan behind booked months cannot change; only the description may.
                $accrual->forceFill(['name' => $validated['name'], 'notes' => $validated['notes'] ?? null])->save();
                Audit::record('accrual.updated', $accrual, ['name' => $accrual->name], $company, $user);

                return $accrual;
            }

            $costCenter = blank($validated['cost_center'] ?? null) ? null : CostCenter::query()->where('code', $validated['cost_center'])->first()
                ?? throw ValidationException::withMessages(['cost_center' => __('Kostnadsstället finns inte.')]);

            $attributes = [
                'name' => $validated['name'],
                'kind' => $kind,
                'total_ore' => $total,
                'balance_account' => (int) $validated['balance_account'],
                'result_account' => (int) $validated['result_account'],
                'start_month' => (string) $validated['start_month'],
                'months' => $kind->isSpread() ? (int) $validated['months'] : 1,
                'move_from_result' => $kind->isSpread() && (bool) ($validated['move_from_result'] ?? false),
                'cost_center_id' => $costCenter?->id,
                'notes' => $validated['notes'] ?? null,
            ];

            if ($accrual === null) {
                // The company row is the lock, so two new accruals never get the same number.
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $accrual = Accrual::create([...$attributes, 'number' => (int) Accrual::query()->max('number') + 1, 'created_by' => $user->id]);
                Audit::record('accrual.created', $accrual, ['name' => $accrual->name, 'kind' => $kind->value, 'total_ore' => $total], $company, $user);

                return $accrual;
            }

            $accrual->forceFill($attributes)->save();
            Audit::record('accrual.updated', $accrual, ['name' => $accrual->name], $company, $user);

            return $accrual;
        }));
    }

    /** Removes an accrual nothing has been booked for. */
    public function delete(User $user, Accrual $accrual): void
    {
        $company = Company::query()->findOrFail($accrual->company_id);
        $this->authorize($user, $company);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $accrual): void {
            if ($accrual->postings()->exists()) {
                throw ValidationException::withMessages(['accrual' => __('Periodiseringen har bokförda månader och kan inte tas bort.')]);
            }

            Audit::record('accrual.deleted', $accrual, ['name' => $accrual->name], $company, $user);
            $accrual->delete();
        }));
    }

    /**
     * What a month's run would book.
     *
     * @return list<array{accrual: Accrual, type: string, amount: int}>
     */
    public function preview(Company $company, string $month): array
    {
        return CurrentCompany::run($company, function () use ($month): array {
            $rows = [];

            foreach (Accrual::query()->with('postings')->where('start_month', '<=', $month)->orderBy('number')->get() as $accrual) {
                foreach ($this->stepsFor($accrual, $month) as $type => $amount) {
                    $rows[] = ['accrual' => $accrual, 'type' => $type, 'amount' => $amount];
                }
            }

            return $rows;
        });
    }

    /**
     * Books a month's run (YYYY-MM): at most one voucher on the first day
     * (reversals) and one on the last day (the rest).
     *
     * @return list<Voucher>
     */
    public function run(User $user, Company $company, string $month): array
    {
        $this->authorize($user, $company);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => __('Välj en månad.')]);
        }

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $month): array {
            Accrual::query()->where('start_month', '<=', $month)->lockForUpdate()->get(['id']);
            $rows = $this->preview($company, $month);

            if ($rows === []) {
                return [];
            }

            $this->ensureAccounts(collect($rows)->flatMap(fn (array $row): array => [$row['accrual']->balance_account, $row['accrual']->result_account])->unique()->all());
            $costCenters = CostCenter::query()->whereIn('id', collect($rows)->pluck('accrual.cost_center_id')->filter())->pluck('code', 'id');
            $first = CarbonImmutable::parse("{$month}-01");
            $vouchers = [];

            foreach ([
                [array_values(array_filter($rows, fn (array $row): bool => $row['type'] === AccrualPosting::REVERSAL)), $first, __('Återföring av periodiseringar :month', ['month' => $first->subMonthNoOverflow()->format('Y-m')])],
                [array_values(array_filter($rows, fn (array $row): bool => $row['type'] !== AccrualPosting::REVERSAL)), $first->endOfMonth()->startOfDay(), __('Periodiseringar :month', ['month' => $month])],
            ] as [$group, $date, $description]) {
                if ($group === []) {
                    continue;
                }

                $lines = [];

                foreach ($group as $row) {
                    $accrual = $row['accrual'];
                    // Releasing and accruing move the amount into the result; opening and reversing move it out.
                    $direction = in_array($row['type'], [AccrualPosting::RELEASE, AccrualPosting::ACCRUAL], true) ? 1 : -1;
                    $result = $accrual->kind->sign() * $direction * $row['amount'];
                    $text = "{$accrual->number} {$accrual->name}";
                    $lines[] = ['account' => $accrual->result_account, 'amount' => $result, 'description' => $text, 'cost_center' => $costCenters[$accrual->cost_center_id] ?? null];
                    $lines[] = ['account' => $accrual->balance_account, 'amount' => -$result, 'description' => $text];
                }

                $voucher = $this->book->rows($user, $company, $date, $description, self::SERIES, $lines, VoucherSource::Closing, $group[0]['accrual']);

                foreach ($group as $row) {
                    AccrualPosting::create(['accrual_id' => $row['accrual']->id, 'period' => $month, 'type' => $row['type'], 'amount_ore' => $row['amount'], 'voucher_id' => $voucher->id]);
                }

                $vouchers[] = $voucher;
            }

            Audit::record('accrual.booked', $vouchers[0], ['month' => $month, 'vouchers' => array_map(fn (Voucher $voucher): string => $voucher->reference(), $vouchers)], $company, $user);

            return $vouchers;
        }));
    }

    /**
     * The steps an accrual needs in a month, by type.
     *
     * @return array<string, int>
     */
    private function stepsFor(Accrual $accrual, string $month): array
    {
        $steps = [];

        if ($accrual->kind->isSpread()) {
            if ($accrual->move_from_result && ! $accrual->hasBooked(AccrualPosting::OPENING)) {
                $steps[AccrualPosting::OPENING] = $accrual->total_ore->ore;
            }

            $release = $accrual->plan()->releasedBy($month) - $accrual->bookedOre(AccrualPosting::RELEASE);

            if ($release > 0) {
                $steps[AccrualPosting::RELEASE] = $release;
            }

            return $steps;
        }

        // An accrued item belongs to its own month only; it is reversed in any later month's run.
        if ($accrual->start_month === $month && ! $accrual->hasBooked(AccrualPosting::ACCRUAL)) {
            $steps[AccrualPosting::ACCRUAL] = $accrual->total_ore->ore;
        }

        if (AccrualPlan::monthsBetween($accrual->start_month, $month) > 0 && $accrual->hasBooked(AccrualPosting::ACCRUAL) && ! $accrual->hasBooked(AccrualPosting::REVERSAL)) {
            $steps[AccrualPosting::REVERSAL] = $accrual->bookedOre(AccrualPosting::ACCRUAL);
        }

        return $steps;
    }

    /**
     * @param  array<int, int>  $numbers
     */
    private function ensureAccounts(array $numbers): void
    {
        $existing = Account::query()->whereIn('number', $numbers)->where('is_active', true)->pluck('number')->map(fn (mixed $n): int => (int) $n)->all();
        $missing = array_values(array_diff($numbers, $existing));

        if ($missing !== []) {
            throw ValidationException::withMessages(['accounts' => __('Konto :accounts finns inte i kontoplanen. Lägg till det först.', ['accounts' => implode(', ', $missing)])]);
        }
    }

    private function money(string $value): int
    {
        try {
            return Money::parse($value)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['total' => __('Beloppet går inte att läsa. Skriv till exempel 12 500 eller 12 500,50.')]);
        }
    }

    private function authorize(User $user, Company $company): void
    {
        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }
    }
}
