<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

use App\Modules\Inbox\Domain\FieldRole;
use App\Support\Money\Money;

/**
 * Builds the voucher of a daily report from its amounts and how each maps
 * to an account, and checks it: debit equals credit, and the VAT per rate
 * agrees with the sales at that rate. The same code serves NONOX AI's
 * proposal, a person's mapping and a saved template, so the numbers never
 * come from the AI itself.
 *
 * Which accounts sales, VAT, payments, tips and rounding go to is the
 * company's choice. TODO(legal-verify): the booking of tips (dricks) paid
 * by card as a debt to the staff, gift cards (presentkort: single- or
 * multi-purpose vouchers, ML 2023:200 ch. 3) and öresavrundning on a
 * daily report have not been verified by an accountant; NONOX only
 * proposes, a person approves.
 */
final class DailyReportVoucher
{
    /** VAT rates (percent) a mapping may name. TODO(legal-verify): Swedish rates in force on the report date. */
    public const array VAT_RATES = [25, 12, 6, 0];

    /**
     * @param  array<string, array{label: string, amount_ore: int}>  $fields
     * @param  array<string, array<string, mixed>>  $mapping  key => role, account, side ("D"/"K"), vat_rate, reason, confidence
     * @param  list<int>  $chart  active account numbers of the company
     * @return array{rows: list<array{account: int, debit_ore: int, credit_ore: int, description: string, source: string, reason: string, confidence: ?float}>, debit: int, credit: int, balanced: bool, vat: array<int, array{net: int, vat: int}>, warnings: list<array{code: string, message: string}>}
     */
    public static function build(array $fields, array $mapping, array $chart): array
    {
        $rows = [];
        $warnings = [];
        $vatByRate = [];
        $vatAmounts = [];

        foreach ($mapping as $key => $entry) {
            if (FieldRole::tryFrom((string) ($entry['role'] ?? '')) === FieldRole::OutputVat && isset($fields[$key])) {
                $rate = (int) ($entry['vat_rate'] ?? -1);
                $vatAmounts[$rate] = ($vatAmounts[$rate] ?? 0) + $fields[$key]['amount_ore'];
            }
        }

        foreach ($fields as $key => $field) {
            $entry = $mapping[$key] ?? null;
            $role = FieldRole::tryFrom((string) ($entry['role'] ?? '')) ?? FieldRole::Ignore;

            if ($entry === null || $role === FieldRole::Ignore) {
                continue;
            }

            $account = (int) ($entry['account'] ?? 0);

            if (! in_array($account, $chart, true)) {
                $warnings[] = ['code' => 'UNMAPPED', 'message' => __('":label" saknar ett konto som finns i kontoplanen.', ['label' => $field['label']])];

                continue;
            }

            $amount = $field['amount_ore'];
            $rate = isset($entry['vat_rate']) ? (int) $entry['vat_rate'] : null;

            if ($role === FieldRole::SalesInclVat) {
                $amount -= $vatAmounts[$rate ?? -1] ?? 0;
            }

            if ($role->isSales() && $rate !== null) {
                $vatByRate[$rate]['net'] = ($vatByRate[$rate]['net'] ?? 0) + $amount;
            }

            if ($role === FieldRole::OutputVat && $rate !== null) {
                $vatByRate[$rate]['vat'] = ($vatByRate[$rate]['vat'] ?? 0) + $amount;
            }

            if ($amount === 0) {
                continue;
            }

            $side = in_array($entry['side'] ?? null, ['D', 'K'], true) ? (string) $entry['side'] : $role->defaultSide();

            if ($amount < 0) {
                $side = $side === 'D' ? 'K' : 'D';
                $amount = -$amount;
            }

            $rows[] = [
                'account' => $account,
                'debit_ore' => $side === 'D' ? $amount : 0,
                'credit_ore' => $side === 'K' ? $amount : 0,
                'description' => mb_substr($field['label'], 0, 255),
                'source' => (string) $key,
                'reason' => mb_substr((string) ($entry['reason'] ?? ''), 0, 500),
                'confidence' => isset($entry['confidence']) ? round((float) $entry['confidence'], 2) : null,
            ];
        }

        $vat = [];

        foreach ($vatByRate as $rate => $sums) {
            $vat[(int) $rate] = ['net' => (int) ($sums['net'] ?? 0), 'vat' => (int) ($sums['vat'] ?? 0)];
        }

        ksort($vat);
        $warnings = [...$warnings, ...self::vatWarnings($vat)];
        $debit = array_sum(array_column($rows, 'debit_ore'));
        $credit = array_sum(array_column($rows, 'credit_ore'));

        if ($rows === []) {
            $warnings[] = ['code' => 'EMPTY', 'message' => __('Inget belopp är kopplat till ett konto ännu.')];
        } elseif ($debit !== $credit) {
            $warnings[] = ['code' => 'UNBALANCED', 'message' => __('Förslaget balanserar inte: debet :debit, kredit :credit.', [
                'debit' => Money::ofOre($debit)->format(),
                'credit' => Money::ofOre($credit)->format(),
            ])];
        }

        return [
            'rows' => $rows,
            'debit' => $debit,
            'credit' => $credit,
            'balanced' => $rows !== [] && $debit === $credit,
            'vat' => $vat,
            'warnings' => $warnings,
        ];
    }

    /**
     * Whether the VAT at each rate is what the sales at that rate give. A
     * report sums the VAT per receipt, so a difference of a krona plus 0,1 %
     * is accepted as rounding.
     *
     * @param  array<int, array{net: int, vat: int}>  $vat
     * @return list<array{code: string, message: string}>
     */
    private static function vatWarnings(array $vat): array
    {
        $warnings = [];

        foreach ($vat as $rate => $sums) {
            if (! in_array($rate, self::VAT_RATES, true)) {
                $warnings[] = ['code' => 'VAT_RATE', 'message' => __('Momssatsen :rate % finns inte i Sverige.', ['rate' => $rate])];

                continue;
            }

            $expected = intdiv($sums['net'] * $rate * 2 + ($sums['net'] >= 0 ? 100 : -100), 200);
            $tolerance = max(100, intdiv(abs($sums['net']), 1000));

            if (abs($sums['vat'] - $expected) > $tolerance) {
                $warnings[] = ['code' => 'VAT_MISMATCH', 'message' => __('Momsen :rate % är :vat men försäljningen :net ger :expected.', [
                    'rate' => $rate,
                    'vat' => Money::ofOre($sums['vat'])->format(),
                    'net' => Money::ofOre($sums['net'])->format(),
                    'expected' => Money::ofOre($expected)->format(),
                ])];
            }
        }

        return $warnings;
    }
}
