<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\PriorYearSizeFigure;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Saves the size figures of a fiscal year before the company's first year
 * in NONOX (ADR-059, addendum 2026-11-07, A.2.5): average employees as
 * full-time equivalents, the balance sheet total and the net turnover, as
 * the size test (ÅRL 1 kap. 3 §, BFNAR 2006:11) needs them. A year kept in
 * NONOX is read from the ledger and cannot be entered. One row per start
 * date; saving the same year again updates it. Needs fiscal-years:manage.
 */
class SavePriorYearSizeFigures
{
    /** BFL 3 kap. 3 §: a changed or first fiscal year is at most 18 months (AGENTS.md §6.2). */
    private const int MAX_MONTHS = 18;

    /**
     * @param  array<string, mixed>  $input  id (optional), start_date, end_date (Y-m-d), average_employees_fte ("3,4", may be empty), balance_sheet_total, net_turnover (kronor, "1 234,50")
     */
    public function __invoke(User $user, Company $company, array $input): PriorYearSizeFigure
    {
        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, fn (): PriorYearSizeFigure => DB::transaction(function () use ($user, $company, $input): PriorYearSizeFigure {
            $existing = isset($input['id']) ? PriorYearSizeFigure::query()->lockForUpdate()->findOrFail((int) $input['id']) : null;
            $validated = $this->validate($input);
            $start = CarbonImmutable::parse($validated['start_date']);
            $end = CarbonImmutable::parse($validated['end_date']);

            $figures = $existing ?? PriorYearSizeFigure::query()->whereDate('start_date', $start->toDateString())->lockForUpdate()->first() ?? new PriorYearSizeFigure;

            $this->guardPeriod($start, $end, $figures);

            $values = [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'average_employees_fte' => $validated['average_employees_fte'],
                'balance_sheet_total_ore' => $validated['balance_sheet_total_ore'],
                'net_turnover_ore' => $validated['net_turnover_ore'],
                'entered_by' => $user->id,
            ];

            if (! $figures->exists) {
                // Two people saving the same new year at once: the second insert is ignored and both update the one row.
                PriorYearSizeFigure::query()->insertOrIgnore($values + ['company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);
                $figures = PriorYearSizeFigure::query()->whereDate('start_date', $values['start_date'])->lockForUpdate()->firstOrFail();
            }

            try {
                DB::transaction(fn (): bool => $figures->fill($values)->save());
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['start_date' => __('Perioden överlappar ett annat räkenskapsår som redan har uppgifter.')]);
            }

            Audit::record('prior_year_size_figures.saved', $figures, [
                'start_date' => $values['start_date'],
                'end_date' => $values['end_date'],
                'average_employees_fte' => $values['average_employees_fte'],
                'balance_sheet_total_ore' => $values['balance_sheet_total_ore'],
                'net_turnover_ore' => $values['net_turnover_ore'],
            ], $company, $user);

            return $figures;
        }));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{start_date: string, end_date: string, average_employees_fte: ?string, balance_sheet_total_ore: int, net_turnover_ore: int}
     */
    private function validate(array $input): array
    {
        $validated = Validator::make($input, [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after:start_date'],
            'average_employees_fte' => ['nullable', 'string', 'regex:/^\d{1,6}([,.]\d{1,2})?$/'],
            'balance_sheet_total' => ['required', 'string', 'max:30'],
            'net_turnover' => ['required', 'string', 'max:30'],
        ], [
            'average_employees_fte.regex' => __('Ange medelantal anställda som ett tal med högst två decimaler, till exempel 3,4.'),
        ], [
            'start_date' => __('räkenskapsårets första dag'),
            'end_date' => __('räkenskapsårets sista dag'),
            'average_employees_fte' => __('medelantal anställda'),
            'balance_sheet_total' => __('balansomslutning'),
            'net_turnover' => __('nettoomsättning'),
        ])->validate();

        $amounts = [];

        foreach (['balance_sheet_total', 'net_turnover'] as $field) {
            try {
                $amounts[$field] = Money::parse((string) $validated[$field])->ore;
            } catch (InvalidArgumentException|MathException) {
                throw ValidationException::withMessages([$field => __('Beloppet går inte att läsa. Skriv till exempel 1 234 567,89.')]);
            }
        }

        if ($amounts['balance_sheet_total'] < 0) {
            throw ValidationException::withMessages(['balance_sheet_total' => __('Balansomslutningen kan inte vara negativ.')]);
        }

        $fte = $validated['average_employees_fte'] ?? null;

        return [
            'start_date' => (string) $validated['start_date'],
            'end_date' => (string) $validated['end_date'],
            'average_employees_fte' => $fte === null || $fte === '' ? null : (string) BigDecimal::of(str_replace(',', '.', (string) $fte))->toScale(2),
            'balance_sheet_total_ore' => $amounts['balance_sheet_total'],
            'net_turnover_ore' => $amounts['net_turnover'],
        ];
    }

    private function guardPeriod(CarbonImmutable $start, CarbonImmutable $end, PriorYearSizeFigure $figures): void
    {
        if ($start->day !== 1 || ! $end->isSameDay($end->endOfMonth())) {
            throw ValidationException::withMessages(['start_date' => __('Ett räkenskapsår börjar den första dagen i en månad och slutar den sista dagen i en månad.')]);
        }

        if ($start->addMonths(self::MAX_MONTHS)->subDay()->lessThan($end)) {
            throw ValidationException::withMessages(['end_date' => __('Ett räkenskapsår kan vara högst 18 månader.')]);
        }

        $first = FiscalYear::query()->orderBy('start_date')->first();

        if ($first instanceof FiscalYear && ! $end->lessThan($first->start_date)) {
            throw ValidationException::withMessages(['start_date' => __('Uppgifterna gäller bara räkenskapsår före företagets första räkenskapsår i NONOX, som börjar :date. Senare år läses ur bokföringen.', ['date' => $first->start_date->toDateString()])]);
        }

        $overlap = PriorYearSizeFigure::query()
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->when($figures->exists, fn ($query) => $query->whereKeyNot($figures->id))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => __('Perioden överlappar ett annat räkenskapsår som redan har uppgifter.')]);
        }
    }
}
