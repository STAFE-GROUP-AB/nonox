<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Models\Voucher;
use App\Models\VoucherRow;

/**
 * The tamper-evident chain over posted vouchers (AGENTS.md §6.4, ADR-007):
 * hash = sha256(previous_hash || canonical_json(voucher + rows)).
 *
 * The voucher must have its rows (with their accounts) and its series loaded.
 */
final class LedgerHash
{
    public static function of(Voucher $voucher, ?string $previousHash): string
    {
        return hash('sha256', ($previousHash ?? '').self::canonicalJson($voucher));
    }

    public static function canonicalJson(Voucher $voucher): string
    {
        $rows = $voucher->rows->sortBy('id')->values()->map(fn (VoucherRow $row): array => [
            'account' => $row->account?->number,
            'credit_ore' => $row->credit_ore->ore,
            'debit_ore' => $row->debit_ore->ore,
            'description' => $row->description,
            'cost_center_id' => $row->cost_center_id,
            'project_id' => $row->project_id,
            'quantity' => $row->quantity === null ? null : (string) $row->quantity,
        ])->all();

        $payload = [
            'company_id' => $voucher->company_id,
            'corrects_voucher_id' => $voucher->corrects_voucher_id,
            'description' => $voucher->description,
            'fiscal_year_id' => $voucher->fiscal_year_id,
            'id' => $voucher->id,
            'number' => $voucher->number,
            'posted_at' => $voucher->posted_at?->format('Y-m-d H:i:s'),
            'posted_by' => $voucher->posted_by,
            'rows' => $rows,
            'series' => $voucher->series?->code,
            'source' => $voucher->source->value,
            'voucher_date' => $voucher->voucher_date->toDateString(),
        ];

        return (string) json_encode(self::sortKeys($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => is_array($item) ? self::sortKeys($item) : $item, $value);
    }
}
