<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Support;

use App\Modules\Agreements\Domain\AgreementCostsStep;
use App\Modules\Payroll\Models\CollectiveAgreement;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use Carbon\CarbonImmutable;

/**
 * The basis the employer reports to Fora (arbetare: cash gross pay per
 * person and month) and to Collectum (tjänstemän: salary and ITP premium)
 * (ADR-033 A10, gap G23), from approved payroll runs paid in a month. One
 * row per employee and agreement, with the premiums NONOX calculated.
 *
 * This is NONOX's own basis, not Fora's or Collectum's file format: Fora
 * publishes a JSON file specification for monthly reporting (v. 1.37) that
 * also needs each employee's FOK code (uppmärkning) from Fora, and
 * Collectum's formats are not built. TODO(legal-verify): whether producing
 * those files needs an agreement with them (ADR-033 question 6), and what
 * "kontant bruttolön" includes (here: pay taxed by table or as a one-off
 * amount, without benefits).
 */
final class PremiumBasis
{
    public const string FORA = 'FORA';

    public const string COLLECTUM = 'COLLECTUM';

    /** Run states whose pay has been decided. */
    public const array STATES = [PayrollRun::APPROVED, PayrollRun::PAID, PayrollRun::POSTED];

    /**
     * @return list<array{employee_id: int, personal_number: string, name: string, agreement: string, variant: string, payment_dates: list<string>, cash_gross_ore: int, monthly_salary_ore: ?int, pension_ore: int, insurances: array<string, int>}>
     */
    public static function rows(string $month, string $report): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month) ?: throw new \InvalidArgumentException("Bad month {$month}.");
        $collective = $report === self::FORA ? 'ARBETARE' : 'TJANSTEMAN';
        $runIds = PayrollRun::query()
            ->whereIn('status', self::STATES)
            ->whereBetween('payment_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->pluck('id');

        if ($runIds->isEmpty()) {
            return [];
        }

        $catalog = AgreementVersions::catalog();
        $bindings = CollectiveAgreement::query()->whereNotNull('agreement_key')->get()->keyBy('id')
            ->filter(fn (CollectiveAgreement $binding): bool => $catalog->get("{$binding->agreement_key}|{$binding->variant}")?->agreementRules()->collective() === $collective);

        if ($bindings->isEmpty()) {
            return [];
        }

        $codes = PayCode::query()->pluck('code', 'id');
        $payslips = Payslip::query()
            ->with(['lines', 'employee', 'employment'])
            ->whereIn('payroll_run_id', $runIds)
            ->whereHas('employment', fn ($query) => $query->whereIn('collective_agreement_id', $bindings->keys()))
            ->orderBy('employee_id')
            ->get();
        $dates = PayrollRun::query()->whereIn('id', $runIds)->pluck('payment_date', 'id');
        $rows = [];

        foreach ($payslips as $payslip) {
            $binding = $bindings->get((int) $payslip->employment?->collective_agreement_id);
            $employee = $payslip->employee;

            if (! $binding instanceof CollectiveAgreement || $employee === null) {
                continue;
            }

            $key = "{$payslip->employee_id}|{$binding->id}";
            $rows[$key] ??= [
                'employee_id' => (int) $payslip->employee_id,
                'personal_number' => (string) $employee->personal_number,
                'name' => trim("{$employee->first_name} {$employee->last_name}"),
                'agreement' => $binding->name,
                'variant' => (string) $binding->variant,
                'payment_dates' => [],
                'cash_gross_ore' => 0,
                'monthly_salary_ore' => $payslip->employment?->monthly_salary_ore?->ore,
                'pension_ore' => 0,
                'insurances' => [],
            ];
            $date = $dates->get($payslip->payroll_run_id);
            $rows[$key]['payment_dates'][] = $date instanceof CarbonImmutable ? $date->toDateString() : (string) $date;

            foreach ($payslip->lines as $line) {
                /** @var PayslipLine $line */
                $amount = $line->amount_ore->ore;
                $code = (string) $codes->get($line->pay_code_id);

                if (in_array($line->tax_effect, ['TABLE', 'ONE_OFF'], true) && ! ($line->explanation['benefit'] ?? false)) {
                    $rows[$key]['cash_gross_ore'] += $amount;
                }

                if ($code === 'TJANSTEPENSION') {
                    $rows[$key]['pension_ore'] += $amount;
                }

                if ($code === AgreementCostsStep::PAY_CODE) {
                    $insurance = (string) ($line->explanation['insurance'] ?? 'OVRIGT');
                    $rows[$key]['insurances'][$insurance] = ($rows[$key]['insurances'][$insurance] ?? 0) + $amount;
                }
            }
        }

        return array_values(array_map(fn (array $row): array => [...$row, 'payment_dates' => array_values(array_unique($row['payment_dates']))], $rows));
    }

    /**
     * The rows as CSV cells, the header first. Amounts in kronor with a
     * decimal comma; the personal number as twelve digits.
     *
     * @param  list<array{employee_id: int, personal_number: string, name: string, agreement: string, variant: string, payment_dates: list<string>, cash_gross_ore: int, monthly_salary_ore: ?int, pension_ore: int, insurances: array<string, int>}>  $rows
     * @return list<list<string>>
     */
    public static function csv(array $rows, string $report): array
    {
        $insurances = array_values(array_unique(array_merge(...array_map(fn (array $row): array => array_keys($row['insurances']), $rows ?: [['insurances' => []]]))));
        sort($insurances);
        $header = ['personnummer', 'namn', 'avtal', 'variant', 'utbetalningsdag', 'kontant_bruttolon_kr'];

        if ($report === self::COLLECTUM) {
            $header[] = 'manadslon_kr';
        }

        $header = [...$header, 'tjanstepension_kr', ...array_map(fn (string $key): string => 'premie_'.mb_strtolower($key).'_kr', $insurances)];
        $lines = [$header];

        foreach ($rows as $row) {
            $line = [
                preg_replace('/\D/', '', $row['personal_number']) ?? '',
                $row['name'],
                $row['agreement'],
                $row['variant'],
                implode(' ', $row['payment_dates']),
                self::kronor($row['cash_gross_ore']),
            ];

            if ($report === self::COLLECTUM) {
                $line[] = $row['monthly_salary_ore'] === null ? '' : self::kronor($row['monthly_salary_ore']);
            }

            $line[] = self::kronor($row['pension_ore']);

            foreach ($insurances as $key) {
                $line[] = self::kronor($row['insurances'][$key] ?? 0);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private static function kronor(int $ore): string
    {
        $sign = $ore < 0 ? '-' : '';
        $ore = abs($ore);

        return $sign.intdiv($ore, 100).','.str_pad((string) ($ore % 100), 2, '0', STR_PAD_LEFT);
    }
}
