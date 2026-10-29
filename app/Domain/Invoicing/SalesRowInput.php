<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Actions\Invoicing\SaveArticle;
use App\Models\Account;
use App\Models\Article;
use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Turns rows typed on an invoice, quote or order into row attributes: an
 * optional article, a description, quantity, unit, price excluding VAT,
 * discount, VAT rate and revenue account (the chosen one, the article's,
 * or the default for the VAT rate). Must run inside the company.
 */
final class SalesRowInput
{
    /**
     * @param  list<array<string, mixed>>  $input  article (number), description, quantity, unit, price (kronor), discount (percent), vat_rate, account (number)
     * @return list<array{article_id: ?int, description: string, quantity: string, unit: string, unit_price_ore: int, discount_bp: int, vat_rate: int, account_id: int, net_ore: int}>
     *
     * @throws ValidationException
     */
    public static function parse(array $input, bool $negate = false): array
    {
        $articles = Article::query()->whereIn('number', array_filter(array_map(fn (array $row): string => trim((string) ($row['article'] ?? '')), $input)))->get()->keyBy('number');
        $accountNumbers = array_filter(array_map(fn (array $row): int => (int) ($row['account'] ?? 0), $input));
        $accounts = Account::query()
            ->where(fn ($query) => $query
                ->whereIn('number', [...$accountNumbers, ...array_values(SaveArticle::DEFAULT_ACCOUNTS)])
                ->orWhereIn('id', $articles->pluck('account_id')))
            ->get();
        $byNumber = $accounts->keyBy('number');
        $byId = $accounts->keyBy('id');
        $errors = [];
        $rows = [];

        foreach ($input as $index => $row) {
            $line = $index + 1;
            $article = $articles->get(trim((string) ($row['article'] ?? '')));
            $quantity = InvoiceCalculator::quantity((string) ($row['quantity'] ?? ''));
            $rate = (int) ($row['vat_rate'] ?? 2500);

            if ($quantity === null || preg_match('/^-?0+(\.0+)?$/', $quantity) === 1) {
                $errors["rows.{$index}.quantity"] = __('Rad :line: skriv ett antal, till exempel 2 eller 1,5.', ['line' => $line]);

                continue;
            }

            if ($negate && ! str_starts_with($quantity, '-')) {
                $quantity = "-{$quantity}";
            }

            try {
                $price = Money::parse((string) ($row['price'] ?? '0'));
            } catch (InvalidArgumentException) {
                $errors["rows.{$index}.price"] = __('Rad :line: priset går inte att läsa. Skriv till exempel 149,00.', ['line' => $line]);

                continue;
            }

            $discount = InvoiceCalculator::discount((string) ($row['discount'] ?? ''));

            if ($discount === null) {
                $errors["rows.{$index}.discount"] = __('Rad :line: rabatten ska vara mellan 0 och 100 procent.', ['line' => $line]);

                continue;
            }

            $accountNumber = (int) ($row['account'] ?? 0);
            $account = match (true) {
                $accountNumber > 0 => $byNumber->get($accountNumber),
                $article !== null => $byId->get($article->account_id),
                default => $byNumber->get(SaveArticle::DEFAULT_ACCOUNTS[$rate] ?? 0),
            };

            if (! $account instanceof Account) {
                $errors["rows.{$index}.account"] = __('Rad :line: intäktskontot finns inte i kontoplanen.', ['line' => $line]);

                continue;
            }

            $rows[] = [
                'article_id' => $article?->id,
                'description' => trim((string) ($row['description'] ?? '')),
                'quantity' => $quantity,
                'unit' => mb_substr(trim((string) ($row['unit'] ?? '')) ?: ($article->unit ?? 'st'), 0, 10),
                'unit_price_ore' => $price->ore,
                'discount_bp' => $discount,
                'vat_rate' => $rate,
                'account_id' => $account->id,
                'net_ore' => InvoiceCalculator::rowNet($price, $quantity, $discount)->ore,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }
}
