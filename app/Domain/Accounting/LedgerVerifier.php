<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Recomputes a company's ledger and reports every inconsistency
 * (AGENTS.md §6.4, `ledger:verify`):
 *
 * - the hash chain of each fiscal year, link by link, ending at the stored head;
 * - every posted voucher balances and has at least two rows;
 * - voucher numbers are gapless from 1 in each series and fiscal year.
 */
final class LedgerVerifier
{
    /**
     * @return array{ok: bool, vouchers: int, problems: list<string>}
     */
    public function verify(Company $company): array
    {
        return CurrentCompany::run($company, function (): array {
            $problems = [];
            $count = 0;

            foreach (FiscalYear::query()->orderBy('start_date')->get() as $fiscalYear) {
                $vouchers = Voucher::query()
                    ->where('fiscal_year_id', $fiscalYear->id)
                    ->where('status', VoucherStatus::Posted->value)
                    ->with(['rows.account', 'series', 'attachments'])
                    ->get();

                $count += $vouchers->count();

                array_push($problems, ...$this->chainProblems($fiscalYear, $vouchers));
                array_push($problems, ...$this->balanceProblems($vouchers));
                array_push($problems, ...$this->numberingProblems($fiscalYear, $vouchers));
                array_push($problems, ...$this->attachmentProblems($vouchers));
            }

            return ['ok' => $problems === [], 'vouchers' => $count, 'problems' => $problems];
        });
    }

    /**
     * @param  Collection<int, Voucher>  $vouchers
     * @return list<string>
     */
    private function chainProblems(FiscalYear $fiscalYear, Collection $vouchers): array
    {
        $label = $fiscalYear->label();

        if ($vouchers->isEmpty()) {
            return $fiscalYear->ledger_head_hash === null
                ? []
                : ["Räkenskapsår {$label}: kedjan pekar på en verifikation som saknas."];
        }

        $byPrevious = $vouchers->groupBy(fn (Voucher $voucher): string => $voucher->previous_hash ?? 'start');
        $problems = [];

        foreach ($byPrevious as $previous => $group) {
            if ($group->count() > 1) {
                $problems[] = "Räkenskapsår {$label}: kedjan delar sig efter {$previous}.";
            }
        }

        $current = $byPrevious->get('start')?->first();
        $visited = 0;
        $last = null;

        while ($current instanceof Voucher && $visited <= $vouchers->count()) {
            $visited++;

            if (LedgerHash::of($current, $current->previous_hash) !== $current->hash) {
                $problems[] = "Räkenskapsår {$label}: verifikation {$current->reference()} har ändrats efter bokföringen.";
            }

            $last = $current;
            $current = $byPrevious->get((string) $current->hash)?->first();
        }

        if ($visited !== $vouchers->count()) {
            $problems[] = "Räkenskapsår {$label}: kedjan når {$visited} av {$vouchers->count()} verifikationer.";
        }

        if ($last?->hash !== $fiscalYear->ledger_head_hash) {
            $problems[] = "Räkenskapsår {$label}: kedjans sista länk stämmer inte med den sparade.";
        }

        return $problems;
    }

    /**
     * @param  Collection<int, Voucher>  $vouchers
     * @return list<string>
     */
    private function balanceProblems(Collection $vouchers): array
    {
        $problems = [];

        foreach ($vouchers as $voucher) {
            $debit = $voucher->rows->sum(fn (VoucherRow $row): int => $row->debit_ore->ore);
            $credit = $voucher->rows->sum(fn (VoucherRow $row): int => $row->credit_ore->ore);

            if ($voucher->rows->count() < 2 || $debit !== $credit || $debit === 0) {
                $problems[] = "Verifikation {$voucher->reference()} balanserar inte (debet {$debit}, kredit {$credit} öre).";
            }
        }

        return $problems;
    }

    /**
     * Every stored underlag still exists and is byte for byte what was uploaded.
     *
     * @param  Collection<int, Voucher>  $vouchers
     * @return list<string>
     */
    private function attachmentProblems(Collection $vouchers): array
    {
        $disk = Storage::disk(AttachToVoucher::DISK);
        $problems = [];

        foreach ($vouchers as $voucher) {
            foreach ($voucher->attachments as $attachment) {
                if (! $disk->exists($attachment->file_path)) {
                    $problems[] = "Verifikation {$voucher->reference()}: underlaget \"{$attachment->original_name}\" saknas.";
                } elseif (hash('sha256', (string) $disk->get($attachment->file_path)) !== $attachment->sha256) {
                    $problems[] = "Verifikation {$voucher->reference()}: underlaget \"{$attachment->original_name}\" har ändrats.";
                }
            }
        }

        return $problems;
    }

    /**
     * @param  Collection<int, Voucher>  $vouchers
     * @return list<string>
     */
    private function numberingProblems(FiscalYear $fiscalYear, Collection $vouchers): array
    {
        $problems = [];

        foreach ($vouchers->groupBy('series_id') as $series) {
            $numbers = $series->pluck('number')->map(fn (mixed $number): int => (int) $number)->sort()->values()->all();
            $expected = range(1, count($numbers));

            if ($numbers !== $expected) {
                $code = $series->first()?->series?->code;
                $problems[] = "Räkenskapsår {$fiscalYear->label()}: serie {$code} har luckor eller dubbletter i numreringen.";
            }
        }

        return $problems;
    }
}
