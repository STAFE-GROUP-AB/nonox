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
        $problems = [];
        $companyId = $dump['company']['source_id'] ?? null;

        if (! is_int($companyId)) {
            return ['The dump has no company source id.'];
        }

        $years = [];

        foreach ((array) ($dump['fiscal_years'] ?? []) as $year) {
            if (! is_array($year) || ! is_int($year['id'] ?? null) || ! isset($year['start_date'], $year['end_date']) || ! array_key_exists('ledger_head_hash', $year)) {
                $problems[] = 'A fiscal year in the dump is malformed.';

                continue;
            }

            $years[$year['id']] = $year;
        }

        $posted = [];

        foreach ((array) ($dump['vouchers'] ?? []) as $index => $voucher) {
            $label = is_array($voucher) && is_string($voucher['id'] ?? null) ? $voucher['id'] : "#{$index}";
            $missing = is_array($voucher) ? array_diff(self::VOUCHER_KEYS, array_keys($voucher)) : self::VOUCHER_KEYS;

            if ($missing !== []) {
                $problems[] = "Voucher {$label}: missing ".implode(', ', $missing).'.';

                continue;
            }

            if (! in_array($voucher['status'], ['DRAFT', 'POSTED'], true)) {
                $problems[] = "Voucher {$label}: unknown status ".json_encode($voucher['status']).'.';

                continue;
            }

            if ($voucher['status'] !== 'POSTED') {
                continue;
            }

            if (! is_string($voucher['hash']) || ! (is_string($voucher['previous_hash']) || $voucher['previous_hash'] === null)) {
                $problems[] = "Voucher {$label}: its hash fields are malformed.";

                continue;
            }

            if (! is_int($voucher['fiscal_year_id']) || ! isset($years[$voucher['fiscal_year_id']])) {
                $problems[] = "Voucher {$label}: its fiscal year ".json_encode($voucher['fiscal_year_id']).' is not in the dump.';

                continue;
            }

            if (! is_array($voucher['rows']) || array_filter($voucher['rows'], static fn (mixed $row): bool => ! is_array($row) || array_diff(self::ROW_KEYS, array_keys($row)) !== []) !== []) {
                $problems[] = "Voucher {$label}: a row is malformed.";

                continue;
            }

            $posted[$voucher['fiscal_year_id']][] = $voucher;
        }

        foreach ($years as $id => $year) {
            $problems = [...$problems, ...self::verifyYear($companyId, $year, $posted[$id] ?? [])];
        }

        return $problems;
    }

    private const array VOUCHER_KEYS = [
        'id', 'fiscal_year_id', 'series', 'number', 'status', 'voucher_date', 'description', 'source',
        'posted_at', 'hash', 'previous_hash', 'rows',
    ];

    private const array ROW_KEYS = ['account', 'credit_ore', 'debit_ore', 'description', 'cost_center_id', 'project_id', 'quantity'];

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
