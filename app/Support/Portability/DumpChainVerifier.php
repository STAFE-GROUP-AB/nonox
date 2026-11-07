<?php

declare(strict_types=1);

namespace App\Support\Portability;

/**
 * Recomputes the voucher hash chain (AGENTS.md section 6.4, ADR-007) from a
 * decoded dump alone, with no database. The payload is the one
 * `App\Domain\Accounting\LedgerHash::canonicalJson` builds; a test compares
 * the two so they cannot drift apart.
 *
 * It proves the dump is the ledger that was exported. The importer uses it
 * before writing anything (ADR-065, section 2).
 */
final class DumpChainVerifier
{
    /**
     * @param  array<string, mixed>  $dump
     * @return list<string> Problems; empty when every chain is intact.
     */
    public static function verify(array $dump): array
    {
        $companyId = (int) ($dump['company']['source_id'] ?? 0);
        $problems = [];
        $posted = [];

        foreach ((array) ($dump['vouchers'] ?? []) as $voucher) {
            if (($voucher['status'] ?? null) === 'POSTED') {
                $posted[(int) $voucher['fiscal_year_id']][] = $voucher;
            }
        }

        foreach ((array) ($dump['fiscal_years'] ?? []) as $year) {
            $problems = [...$problems, ...self::verifyYear($companyId, $year, $posted[(int) $year['id']] ?? [])];
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $voucher
     */
    public static function hashOf(int $companyId, array $voucher, ?string $previousHash): string
    {
        return hash('sha256', ($previousHash ?? '').self::canonicalJson($companyId, $voucher));
    }

    /**
     * @param  array<string, mixed>  $voucher
     */
    public static function canonicalJson(int $companyId, array $voucher): string
    {
        $rows = array_map(static fn (array $row): array => [
            'account' => $row['account'],
            'credit_ore' => $row['credit_ore'],
            'debit_ore' => $row['debit_ore'],
            'description' => $row['description'],
            'cost_center_id' => $row['cost_center_id'],
            'project_id' => $row['project_id'],
            'quantity' => $row['quantity'],
        ], $voucher['rows']);

        $payload = [
            'company_id' => $companyId,
            'corrects_voucher_id' => $voucher['corrects_voucher_id'] ?? null,
            'description' => $voucher['description'],
            'fiscal_year_id' => $voucher['fiscal_year_id'],
            'id' => $voucher['id'],
            'number' => $voucher['number'],
            'posted_at' => $voucher['posted_at'],
            'posted_by' => $voucher['posted_by'] ?? null,
            'rows' => $rows,
            'series' => $voucher['series'],
            'source' => $voucher['source'],
            'voucher_date' => $voucher['voucher_date'],
        ];

        return (string) json_encode(self::sortKeys($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $year
     * @param  list<array<string, mixed>>  $vouchers
     * @return list<string>
     */
    private static function verifyYear(int $companyId, array $year, array $vouchers): array
    {
        $label = "{$year['start_date']}–{$year['end_date']}";

        if ($vouchers === []) {
            return ($year['ledger_head_hash'] ?? null) === null ? [] : ["Fiscal year {$label}: the head hash points at a voucher that is not in the dump."];
        }

        $byPrevious = [];
        $problems = [];

        foreach ($vouchers as $voucher) {
            $key = $voucher['previous_hash'] ?? 'start';

            if (isset($byPrevious[$key])) {
                $problems[] = "Fiscal year {$label}: the chain forks after {$key}.";
            }

            $byPrevious[$key] = $voucher;
        }

        $current = $byPrevious['start'] ?? null;
        $visited = 0;
        $last = null;

        while ($current !== null && $visited <= count($vouchers)) {
            $visited++;

            if (self::hashOf($companyId, $current, $current['previous_hash']) !== $current['hash']) {
                $problems[] = "Fiscal year {$label}: voucher {$current['series']}{$current['number']} does not match its hash.";
            }

            $last = $current;
            $current = $byPrevious[$current['hash']] ?? null;
        }

        if ($visited !== count($vouchers)) {
            $problems[] = "Fiscal year {$label}: the chain reaches {$visited} of ".count($vouchers).' vouchers.';
        }

        if (($last['hash'] ?? null) !== ($year['ledger_head_hash'] ?? null)) {
            $problems[] = "Fiscal year {$label}: the last link does not match the stored head hash.";
        }

        return $problems;
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

        return array_map(static fn (mixed $item): mixed => is_array($item) ? self::sortKeys($item) : $item, $value);
    }
}
