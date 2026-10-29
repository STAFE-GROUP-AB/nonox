<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing\Concerns;

use Livewire\Component;

/**
 * The rows of an invoice, quote or order being written. Adding rows,
 * filling them from articles and the running totals happen in the browser
 * (resources/js/sales-rows.js); the rows reach the server when the document
 * is saved, and the server calculates everything again.
 *
 * @mixin Component
 */
trait EditsSalesRows
{
    /** @var array<int, array<string, string>> */
    public array $rows = [];

    /**
     * The filled rows as input for the save action.
     *
     * @return list<array<string, mixed>>
     */
    protected function rowInput(): array
    {
        return array_map(fn (array $row): array => [
            ...$row,
            'vat_rate' => (int) ($row['vat_rate'] ?? 2500),
            'account' => ($row['account'] ?? '') === '' ? null : (int) $row['account'],
        ], $this->filledRows());
    }

    /**
     * @param  iterable<object>  $rows  invoice or quote/order rows with their account
     * @return array<int, array<string, string>>
     */
    protected function rowsFrom(iterable $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $input = method_exists($row, 'asInput') ? $row->asInput() : [];
            $result[] = array_map(fn (mixed $value): string => (string) $value, ['article' => '', ...$input]);
        }

        return $result;
    }

    /**
     * @return list<array<string, string>>
     */
    private function filledRows(): array
    {
        return array_values(array_filter($this->rows, fn (array $row): bool => trim((string) ($row['description'] ?? '')) !== '' || trim((string) ($row['price'] ?? '')) !== ''));
    }

    /** @return array<string, string> */
    private function blankRow(): array
    {
        return ['article' => '', 'description' => '', 'quantity' => '1', 'unit' => 'st', 'price' => '', 'discount' => '', 'vat_rate' => '2500', 'account' => ''];
    }
}
