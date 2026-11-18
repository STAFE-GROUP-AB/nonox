<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Company\AccountingMethod;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Domain\Vat\OssRates;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\Project;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * How invoices and payments are booked (AGENTS.md §7 Phase 2), as signed öre
 * per account number (debit positive):
 *
 * - Fakturametoden, when issued: 1510 Kundfordringar debit, each revenue
 *   account and the output VAT per rate credit, öresavrundning on 3740.
 *   A payment then moves the amount from 1510 to the bank account.
 * - Kontantmetoden: nothing when issued. A payment books its share of the
 *   revenue, VAT and rounding directly against the bank account. The
 *   payment that settles the invoice books what is left of each line, so
 *   the payments together book exactly the invoice's revenue and VAT
 *   (ML 2023:200 7 kap. 16 §, 17 kap. 24 § p. 11) (enligt granskning
 *   2026-11-02, juristgranskning återstår (AUD-FAKT-3, #52)).
 * - Except EU sales reported for the invoice's period (ForeignSale::
 *   isReportedWhenInvoiced, ML 7 kap. 29-30 §§): they are booked as under
 *   fakturametoden whatever the method, and a payment only clears 1510
 *   (enligt granskning 2026-11-02, juristgranskning återstår (AUD-FAKT-4, #50)).
 *
 * A sale abroad (ForeignSale) is booked on the account for that kind of
 * sale instead of the rows' accounts, without VAT. A sale to a consumer in
 * another EU country under the OSS threshold (OssThreshold) carries Swedish
 * VAT and is booked like a sale in Sweden.
 *
 * A credit invoice has negative amounts, so the same rules reverse it.
 *
 * Cost centre and project (ADR-081 gap 6): the revenue of each row is booked
 * on the row's cost centre and project, so the revenue rows of the voucher
 * are grouped by account and dimensions instead of by account only; VAT,
 * receivable, rounding, bank and exchange rows carry none (issueRows). Under
 * kontantmetoden a payment first takes its share of each account exactly as
 * before, then splits each revenue account's share over that account's
 * dimensions in proportion to the rows, in whole öre by the largest
 * remainder; the payment that settles the invoice gives each dimension what
 * is left of it, as it does per account (paymentRows). An invoice without
 * dimensions is booked exactly as before.
 * TODO(legal-verify): the proportional split on kontantmetoden payment
 * vouchers needs the same review as the supplier side (AUD-FAKT-3, ADR-081
 * gap 6).
 *
 * An invoice in another currency is booked in kronor at its booking rate
 * (ADR-025). A payment settles its share of those kronor, and the
 * difference to the kronor that reached the bank is an exchange gain on
 * 3960 or a loss on 7960; under kontantmetoden the revenue and VAT are
 * booked at the booking rate, so the VAT follows the rate of the taxable
 * event and not the payment day.
 *
 * TODO(legal-verify): under kontantmetoden, invoices still unpaid at the end
 * of the fiscal year must be booked as receivables; that belongs to bokslut.
 */
final class InvoiceBooking
{
    public const int RECEIVABLES = 1510;

    public const int ROUNDING = 3740;

    /**
     * BAS: Konstaterade förluster på kundfordringar.
     *
     * TODO(legal-verify): the account against BAS 2026.
     */
    public const int CUSTOMER_LOSS = 6351;

    public const int BANK = 1930;

    public const int EXCHANGE_GAIN = SupplierInvoiceBooking::EXCHANGE_GAIN;

    public const int EXCHANGE_LOSS = SupplierInvoiceBooking::EXCHANGE_LOSS;

    /**
     * Sales of construction services under omvänd betalningsskyldighet.
     *
     * TODO(legal-verify): BAS 3231 and momsdeklaration box 41.
     */
    public const int REVERSE_CHARGE_CONSTRUCTION = 3231;

    /** Output VAT account per rate in basis points. */
    public const array OUTPUT_VAT = [2500 => 2611, 1200 => 2621, 600 => 2631];

    /**
     * Revenue, VAT and rounding of the invoice, credit negative.
     *
     * @param  Collection<int, InvoiceRow>  $rows  with their accounts
     * @return array<int, int>
     */
    public static function revenue(Invoice $invoice, Collection $rows): array
    {
        $lines = [];
        $sale = $invoice->bookedForeignSale();

        foreach ($rows as $row) {
            $number = self::revenueAccount($invoice, $sale, $row);
            $lines[$number] = ($lines[$number] ?? 0) - $row->net_ore->ore;
        }

        $totals = InvoiceCalculator::totals($rows->map(fn (InvoiceRow $row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->values()->all());

        foreach ($totals['vat_by_rate'] as $rate => $group) {
            if ($group['vat'] !== 0) {
                $account = $sale === ForeignSale::EuConsumerOss ? OssRates::VAT_ACCOUNT : self::OUTPUT_VAT[$rate];
                $lines[$account] = ($lines[$account] ?? 0) - $group['vat'];
            }
        }

        if ($invoice->rounding_ore->ore !== 0) {
            $lines[self::ROUNDING] = ($lines[self::ROUNDING] ?? 0) - $invoice->rounding_ore->ore;
        }

        return array_filter($lines);
    }

    /**
     * Whether the invoice is booked when issued (on 1510) rather than when
     * paid: always under fakturametoden, and under kontantmetoden for the
     * EU sales the law ties to the invoice's period.
     */
    public static function bookedAtIssue(Invoice $invoice, AccountingMethod $method): bool
    {
        return $method === AccountingMethod::Fakturametoden || $invoice->bookedForeignSale()?->isReportedWhenInvoiced() === true;
    }

    /**
     * The voucher rows when an invoice is booked at issue (bookedAtIssue).
     *
     * @param  Collection<int, InvoiceRow>  $rows
     * @return array<int, int>
     */
    public static function issue(Invoice $invoice, Collection $rows): array
    {
        // A ROT or RUT deduction is a receivable on Skatteverket, not on the customer. On a credit, the
        // part Skatteverket has already paid is a debt to Skatteverket instead of a smaller claim.
        $repayable = $invoice->tax_reduction_repayable_ore->ore;

        return array_filter([
            self::RECEIVABLES => $invoice->total_ore->ore,
            TaxReduction::RECEIVABLE => $invoice->tax_reduction_ore->ore + $repayable,
            TaxReduction::REPAYABLE => -$repayable,
        ]) + self::revenue($invoice, $rows);
    }

    /**
     * As issue(), as voucher rows with the revenue on each row's cost centre
     * and project (ADR-081 gap 6). Without dimensions these are exactly
     * issue()'s lines, in the same order. Needs the rows' account, cost
     * centre and project loaded.
     *
     * @param  Collection<int, InvoiceRow>  $rows
     * @return list<array{account: int, amount: int, cost_center?: ?string, project?: ?string}>
     */
    public static function issueRows(Invoice $invoice, Collection $rows): array
    {
        $weights = self::weights(self::dimensionGroups($invoice, $rows));

        // An account whose rows net to zero is not in issue(), but its dimensions still moved (they sum to zero).
        return self::dimensionRows(self::issue($invoice, $rows), $weights, whole: $weights);
    }

    /**
     * As payment(), as voucher rows. Under kontantmetoden each revenue
     * account's share is split over its cost centres and projects in
     * proportion to the rows, in whole öre by the largest remainder; the
     * payment that settles the invoice takes what is left of each.
     *
     * @param  Collection<int, InvoiceRow>  $rows  with account, cost centre and project
     * @return list<array{account: int, amount: int, cost_center?: ?string, project?: ?string}>
     */
    public static function paymentRows(Invoice $invoice, Collection $rows, int $amount, int $bankAccount, bool $cashMethod, ?int $received = null): array
    {
        $lines = self::payment($invoice, $rows, $amount, $bankAccount, $cashMethod, $received);

        if (! $cashMethod) {
            return self::dimensionRows($lines, []);
        }

        $groups = self::dimensionGroups($invoice, $rows);

        if ($groups === []) {
            return self::dimensionRows($lines, []);
        }

        $final = $amount === $invoice->remaining()->ore && self::cashRest($invoice, self::revenue($invoice, $rows), $amount) !== null;
        $rest = $final ? self::groupRest($invoice, $groups) : null;

        // The settling payment also books the dimensions of an account whose rows net to zero, in full (they sum to zero).
        return self::dimensionRows($lines, self::weights($groups), $rest, whole: $rest);
    }

    /**
     * The revenue of the rows per account and dimensions: account number =>
     * key => amount (credit negative) and codes. Only accounts where some
     * row has a cost centre or a project, unless $all.
     *
     * @param  Collection<int, InvoiceRow>  $rows
     * @return array<int, array<string, array{amount: int, cost_center: ?string, project: ?string}>>
     */
    public static function dimensionGroups(Invoice $invoice, Collection $rows, bool $all = false): array
    {
        $sale = $invoice->bookedForeignSale();
        $groups = [];
        $dimensioned = [];

        foreach ($rows as $row) {
            $number = self::revenueAccount($invoice, $sale, $row);
            $costCenter = $row->cost_center_id === null ? null : $row->getRelationValue('costCenter');
            $project = $row->project_id === null ? null : $row->getRelationValue('project');
            $costCenterCode = $costCenter instanceof CostCenter ? $costCenter->code : null;
            $projectCode = $project instanceof Project ? $project->code : null;
            $key = self::key($costCenterCode, $projectCode);

            $groups[$number][$key] ??= ['amount' => 0, 'cost_center' => $costCenterCode, 'project' => $projectCode];
            $groups[$number][$key]['amount'] -= $row->net_ore->ore;

            if ($costCenterCode !== null || $projectCode !== null) {
                $dimensioned[$number] = true;
            }
        }

        return $all ? $groups : array_intersect_key($groups, $dimensioned);
    }

    /**
     * Splits $total öre over the parts in proportion to their amounts, by
     * the largest remainder: each part gets the floor of its exact share,
     * and the öre left over go one each to the parts with the largest
     * remainders (on a tie, the part that comes first). Deterministic, and
     * the parts always sum to $total. The amounts may have either sign;
     * their sum must not be zero. A negative total is split as its size
     * and given its sign back.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $amounts
     * @return array<TKey, int>
     */
    public static function largestRemainder(int $total, array $amounts): array
    {
        $sum = array_sum($amounts);

        if ($sum === 0) {
            throw new InvalidArgumentException('Nothing to split in proportion to.');
        }

        // A negative total is split as its size and given its sign back, so the extra öre go to the same parts.
        if ($total < 0) {
            return array_map(fn (int $part): int => -$part, self::largestRemainder(-$total, $amounts));
        }

        // The signs are folded so the divisor is positive; flooring then leaves between 0 and n-1 öre over.
        $sign = $sum < 0 ? -1 : 1;
        $divisor = BigInteger::of($sum * $sign);
        $parts = [];
        $remainders = [];
        $order = 0;

        foreach ($amounts as $key => $amount) {
            $exact = BigInteger::of($total)->multipliedBy($amount * $sign);
            $parts[$key] = $exact->dividedBy($divisor, RoundingMode::Floor)->toInt();
            $remainders[] = ['key' => $key, 'remainder' => $exact->mod($divisor), 'order' => $order++];
        }

        $left = $total - array_sum($parts);

        usort($remainders, fn (array $a, array $b): int => $b['remainder']->compareTo($a['remainder']) ?: ($a['order'] <=> $b['order']));

        for ($i = 0; $i < $left; $i++) {
            $parts[$remainders[$i]['key']]++;
        }

        return $parts;
    }

    /**
     * Account lines as voucher rows, each account in $weights split over its
     * dimensions (keys from dimensionGroups): by $rest when it adds up to the
     * line (what is left of each, for the payment that settles the invoice),
     * as the weights are when the line is their sum, or else in proportion
     * to them by the largest remainder. An account in $whole that has no
     * line and whose parts sum to zero gets those parts in full, so its
     * dimensions are booked although the account nets to zero. Accounts
     * without weights are one row without dimensions.
     *
     * @param  array<int, int>  $lines
     * @param  array<int, array<string, int>>  $weights
     * @param  array<int, array<string, int>>|null  $rest
     * @param  array<int, array<string, int>>|null  $whole
     * @return list<array{account: int, amount: int, cost_center?: ?string, project?: ?string}>
     */
    public static function dimensionRows(array $lines, array $weights, ?array $rest = null, ?array $whole = null): array
    {
        $result = [];

        foreach ($lines as $account => $amount) {
            $parts = array_filter($weights[$account] ?? []);

            if ($parts === [] || array_sum($parts) === 0) {
                $result[] = ['account' => $account, 'amount' => $amount];

                continue;
            }

            $shares = match (true) {
                $rest !== null && isset($rest[$account]) && array_sum($rest[$account]) === $amount => $rest[$account],
                $amount === array_sum($parts) => $parts,
                default => self::largestRemainder($amount, $parts),
            };

            foreach ($shares as $key => $share) {
                $result[] = self::dimensionRow($account, $share, (string) $key);
            }
        }

        foreach (array_diff_key($whole ?? [], $lines) as $account => $parts) {
            if (array_sum($parts) !== 0) {
                continue;
            }

            foreach ($parts as $key => $share) {
                $result[] = self::dimensionRow($account, $share, (string) $key);
            }
        }

        return array_values(array_filter($result, fn (array $row): bool => $row['amount'] !== 0));
    }

    /**
     * The amounts of dimensionGroups as weights: account => key => öre.
     *
     * @param  array<int, array<string, array{amount: int, cost_center: ?string, project: ?string}>>  $groups
     * @return array<int, array<string, int>>
     */
    public static function weights(array $groups): array
    {
        return array_map(fn (array $parts): array => array_map(fn (array $part): int => $part['amount'], $parts), $groups);
    }

    /**
     * Weights added together, account and key by account and key.
     *
     * @param  array<int, array<string, int>>  ...$sets
     * @return array<int, array<string, int>>
     */
    public static function addWeights(array ...$sets): array
    {
        $sum = [];

        foreach ($sets as $set) {
            foreach ($set as $account => $parts) {
                foreach ($parts as $key => $amount) {
                    $sum[$account][$key] = ($sum[$account][$key] ?? 0) + $amount;
                }
            }
        }

        return $sum;
    }

    /**
     * The revenue of the rows per dimension over all accounts together:
     * key => öre (credit negative); empty when no row has a dimension.
     *
     * @param  array<int, array<string, int>>  $weights
     * @return array<string, int>
     */
    public static function dimensionTotals(array $weights): array
    {
        $totals = [];

        foreach ($weights as $parts) {
            foreach ($parts as $key => $amount) {
                $totals[$key] = ($totals[$key] ?? 0) + $amount;
            }
        }

        return array_filter($totals);
    }

    /** @return array{account: int, amount: int, cost_center: ?string, project: ?string} */
    private static function dimensionRow(int $account, int $amount, string $key): array
    {
        [$costCenter, $project] = explode("\u{1F}", $key, 2) + [1 => ''];

        return ['account' => $account, 'amount' => $amount, 'cost_center' => $costCenter === '' ? null : $costCenter, 'project' => $project === '' ? null : $project];
    }

    /**
     * Kontantmetoden: what is left of each dimension of each account for the
     * payment that settles the invoice: the rows, plus its issued credit
     * invoices' rows (negative), less each earlier payment's share as
     * paymentRows split it. It sums per account to what cashRest leaves, so
     * the payments together book exactly the invoice's revenue per account
     * and dimensions. A payment registered before dimensions existed is
     * counted as if it had been split too.
     *
     * @param  array<int, array<string, array{amount: int, cost_center: ?string, project: ?string}>>  $groups
     * @return array<int, array<string, int>>
     */
    private static function groupRest(Invoice $invoice, array $groups): array
    {
        $rest = array_map(fn (array $parts): array => array_map(fn (array $part): int => $part['amount'], $parts), $groups);
        $credits = $invoice->creditInvoices()->whereNotNull('number')->with(['rows.account', 'rows.costCenter', 'rows.project'])->get();

        foreach ($credits as $credit) {
            foreach (array_intersect_key(self::dimensionGroups($credit, $credit->rows, all: true), $rest) as $account => $parts) {
                foreach ($parts as $key => $part) {
                    $rest[$account][$key] = ($rest[$account][$key] ?? 0) + $part['amount'];
                }
            }
        }

        $revenue = self::revenue($invoice, $invoice->rows);

        foreach ($invoice->payments()->get() as $payment) {
            $settled = $payment->amount_ore->ore - (int) $payment->exchange_difference_ore;

            foreach (array_intersect_key(self::cashShare($revenue, $settled, $invoice->total_ore->ore), $groups) as $account => $share) {
                $weights = array_filter(array_map(fn (array $part): int => $part['amount'], $groups[$account]));

                if ($weights === [] || array_sum($weights) === 0) {
                    continue;
                }

                foreach (self::largestRemainder($share, $weights) as $key => $part) {
                    $rest[$account][$key] -= $part;
                }
            }
        }

        return $rest;
    }

    private static function key(?string $costCenter, ?string $project): string
    {
        return ($costCenter ?? '')."\u{1F}".($project ?? '');
    }

    /** The account a row's revenue is booked on. */
    private static function revenueAccount(Invoice $invoice, ?ForeignSale $sale, InvoiceRow $row): int
    {
        return match (true) {
            $invoice->reverse_charge => self::REVERSE_CHARGE_CONSTRUCTION,
            $sale !== null => $sale->account(),
            default => $row->account instanceof Account ? $row->account->number : 0,
        };
    }

    /**
     * The voucher rows for a payment that settles $amount öre of the invoice
     * as booked. $received is the kronor that reached $bankAccount when it
     * differs (an invoice in another currency): the bank row then carries
     * $received and the difference is an exchange gain (3960, credit) or
     * loss (7960, debit).
     *
     * @param  Collection<int, InvoiceRow>  $rows
     * @return array<int, int>
     */
    public static function payment(Invoice $invoice, Collection $rows, int $amount, int $bankAccount, bool $cashMethod, ?int $received = null): array
    {
        $received ??= $amount;
        $difference = $amount - $received;
        $exchange = $difference === 0 ? [] : [$difference > 0 ? self::EXCHANGE_LOSS : self::EXCHANGE_GAIN => $difference];

        if (! $cashMethod) {
            return [$bankAccount => $received, self::RECEIVABLES => -$amount] + $exchange;
        }

        $revenue = self::revenue($invoice, $rows);
        $lines = ($amount === $invoice->remaining()->ore ? self::cashRest($invoice, $revenue, $amount) : null)
            ?? self::cashShare($revenue, $amount, $invoice->total_ore->ore);

        return [$bankAccount => $received] + array_filter($lines) + $exchange;
    }

    /**
     * Kontantmetoden: a part payment's share of each line, rounded half up,
     * with the rounding remainder on the largest line so the voucher balances.
     *
     * @param  array<int, int>  $revenue
     * @return array<int, int>
     */
    public static function cashShare(array $revenue, int $amount, int $total): array
    {
        $lines = [];

        foreach ($revenue as $account => $value) {
            $lines[$account] = BigDecimal::of($value)->multipliedBy($amount)->dividedBy($total, 0, RoundingMode::HalfUp)->toInt();
        }

        $remainder = -$amount - array_sum($lines);

        if ($remainder !== 0 && $lines !== []) {
            $largest = array_keys($lines, min($lines), true)[0];
            $lines[$largest] += $remainder;
        }

        return $lines;
    }

    /**
     * Kontantmetoden: the lines of the payment that settles the invoice. It
     * books what is left of each line after the earlier payments, so that
     * together they book exactly the invoice's revenue and VAT, less what
     * its credit invoices took off (ML 2023:200 7 kap. 16 §, 17 kap. 24 §
     * p. 11; enligt granskning 2026-11-02, juristgranskning återstår
     * (AUD-FAKT-3, #52)). Null for a credit invoice, or when the earlier
     * payments do not add up to the rest (then the payment is shared
     * pro rata as before).
     *
     * @param  array<int, int>  $revenue
     * @return array<int, int>|null
     */
    private static function cashRest(Invoice $invoice, array $revenue, int $amount): ?array
    {
        if ($invoice->isCredit()) {
            return null;
        }

        $target = $revenue;
        $credits = $invoice->creditInvoices()->whereNotNull('number')->with('rows.account')->get();

        foreach ($credits as $credit) {
            foreach (self::revenue($credit, $credit->rows) as $account => $value) {
                $target[$account] = ($target[$account] ?? 0) + $value;
            }
        }

        foreach ($invoice->payments()->get() as $payment) {
            $settled = $payment->amount_ore->ore - (int) $payment->exchange_difference_ore;

            foreach (self::cashShare($revenue, $settled, $invoice->total_ore->ore) as $account => $value) {
                $target[$account] = ($target[$account] ?? 0) - $value;
            }
        }

        return array_sum($target) === -$amount ? $target : null;
    }

    /**
     * The voucher rows of a confirmed customer loss of $amount öre, the open
     * part of the invoice: 1510 credit, and the open part's share of the
     * output VAT debit, which reduces the VAT already reported (ML 2023:200
     * 8 kap. 16 §, 7 kap. 43-44 §§); the rest is the loss on 6351. The share
     * is taken of the invoice and its credit invoices together, so it is the
     * VAT of what is actually still owed (enligt granskning 2026-11-02,
     * juristgranskning återstår (AUD-FAKT-12, #57)).
     *
     * @param  Collection<int, InvoiceRow>  $rows
     * @param  Collection<int, Invoice>  $credits  issued credit invoices, with rows and accounts
     * @return array<int, int>
     */
    public static function writeOff(Invoice $invoice, Collection $rows, Collection $credits, int $amount): array
    {
        if ($invoice->isImported()) {
            return self::writeOffImported($invoice, $amount);
        }

        $lines = self::revenue($invoice, $rows);
        $base = $invoice->total_ore->ore;

        foreach ($credits as $credit) {
            foreach (self::revenue($credit, $credit->rows) as $account => $value) {
                $lines[$account] = ($lines[$account] ?? 0) + $value;
            }

            $base += $credit->total_ore->ore;
        }

        $shares = $base === $amount ? array_filter($lines) : self::cashShare(array_filter($lines), $amount, $base);
        $vatAccounts = [...array_values(self::OUTPUT_VAT), OssRates::VAT_ACCOUNT];
        $vat = [];

        foreach ($shares as $account => $share) {
            if (in_array($account, $vatAccounts, true)) {
                $vat[$account] = -$share;
            }
        }

        return array_filter([self::RECEIVABLES => -$amount, self::CUSTOMER_LOSS => $amount - array_sum($vat)] + $vat);
    }

    /**
     * As writeOff(), as voucher rows: the loss on 6351 is split over the
     * cost centres and projects of the revenue it was (the invoice's rows
     * and its credit invoices'), in proportion and in whole öre by the
     * largest remainder, so an account that requires a dimension can take
     * it (ADR-081 gap 6). Without dimensions, exactly writeOff().
     *
     * @param  Collection<int, InvoiceRow>  $rows  with account, cost centre and project
     * @param  Collection<int, Invoice>  $credits  issued credit invoices, with the same relations
     * @return list<array{account: int, amount: int, cost_center?: ?string, project?: ?string}>
     */
    public static function writeOffRows(Invoice $invoice, Collection $rows, Collection $credits, int $amount): array
    {
        $lines = self::writeOff($invoice, $rows, $credits, $amount);

        if ($invoice->isImported()) {
            return self::dimensionRows($lines, []);
        }

        $weights = self::weights(self::dimensionGroups($invoice, $rows, all: true));

        foreach ($credits as $credit) {
            $weights = self::addWeights($weights, self::weights(self::dimensionGroups($credit, $credit->rows, all: true)));
        }

        $totals = self::dimensionTotals($weights);
        $dimensioned = array_filter(array_keys($totals), fn (string $key): bool => $key !== self::key(null, null));

        if ($dimensioned === [] || array_sum($totals) === 0 || ! isset($lines[self::CUSTOMER_LOSS])) {
            return self::dimensionRows($lines, []);
        }

        return self::dimensionRows($lines, [self::CUSTOMER_LOSS => $totals]);
    }

    /**
     * Write-off of an imported open invoice, which has no rows: the output VAT
     * is the open part's share of the imported vat_ore, on the account of the
     * rate those amounts imply; the rest is the loss.
     * TODO(legal-verify): see ImportedInvoiceVat.
     *
     * @return array<int, int>
     */
    private static function writeOffImported(Invoice $invoice, int $amount): array
    {
        $total = $invoice->total_ore->ore;
        $vatShare = $total === 0 ? 0 : BigDecimal::of($invoice->vat_ore->ore)->multipliedBy($amount)->dividedBy($total, 0, RoundingMode::HalfUp)->toInt();
        $rate = ImportedInvoiceVat::rate($invoice);
        $lines = [self::RECEIVABLES => -$amount, self::CUSTOMER_LOSS => $amount - $vatShare];

        if ($vatShare !== 0 && isset(self::OUTPUT_VAT[$rate])) {
            $lines[self::OUTPUT_VAT[$rate]] = $vatShare;
        } elseif ($vatShare !== 0) {
            $lines[self::CUSTOMER_LOSS] += $vatShare;
        }

        return array_filter($lines);
    }

    /**
     * The kronor of the invoice as booked that a payment of $foreign
     * hundredths of its currency settles: its share of the total, rounded
     * half up, and whatever is left when the payment clears the foreign
     * amount, so the receivable ends at zero.
     */
    public static function settledShare(Invoice $invoice, int $foreign): int
    {
        if ($foreign === $invoice->foreignRemaining()) {
            return $invoice->remaining()->ore;
        }

        return BigDecimal::of($invoice->total_ore->ore)->multipliedBy($foreign)->dividedBy((int) $invoice->foreign_total_minor, 0, RoundingMode::HalfUp)->toInt();
    }
}
