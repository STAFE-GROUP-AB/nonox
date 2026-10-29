<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing\Concerns;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Models\Article;
use App\Support\Money\Money;
use InvalidArgumentException;
use Livewire\Component;

/**
 * The rows of an invoice, quote or order being written: add and remove
 * rows, fill a row from an article, and the totals while typing.
 *
 * @mixin Component
 */
trait EditsSalesRows
{
    /** @var array<int, array<string, string>> */
    public array $rows = [];

    public function addRow(): void
    {
        $this->rows[] = $this->blankRow();
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);

        if ($this->rows === []) {
            $this->rows = [$this->blankRow()];
        }
    }

    /**
     * Filling in an article number fills the row from the article.
     */
    public function updatedRows(mixed $value, string $key): void
    {
        if (! preg_match('/^(\d+)\.article$/', $key, $match)) {
            return;
        }

        $article = Article::query()->where('number', strtok(trim((string) $value), ' '))->first();

        if (! $article instanceof Article) {
            return;
        }

        $index = (int) $match[1];
        $this->rows[$index] = [
            ...$this->rows[$index],
            'article' => $article->number,
            'description' => $article->name,
            'unit' => $article->unit,
            'price' => $article->price_ore->format(withCurrency: false),
            'vat_rate' => (string) $article->vat_rate,
            'quantity' => ($this->rows[$index]['quantity'] ?? '') !== '' ? $this->rows[$index]['quantity'] : '1',
            'account' => '',
        ];
    }

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
     * Totals of the rows that can be read, while typing.
     *
     * @return array{net: int, vat: int, rounding: int, total: int, vat_by_rate: array<int, array{base: int, vat: int}>}
     */
    protected function previewTotals(bool $negate = false): array
    {
        $rows = [];

        foreach ($this->filledRows() as $row) {
            $quantity = InvoiceCalculator::quantity((string) ($row['quantity'] ?? ''));

            try {
                $price = Money::parse((string) ($row['price'] ?? ''));
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($quantity === null) {
                continue;
            }

            $discount = InvoiceCalculator::discount((string) ($row['discount'] ?? '')) ?? 0;
            $rows[] = [
                'net' => InvoiceCalculator::rowNet($price, $negate ? "-{$quantity}" : $quantity, $discount)->ore,
                'vat_rate' => (int) ($row['vat_rate'] ?? 2500),
            ];
        }

        return InvoiceCalculator::totals($rows);
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
