<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Actions\Invoicing\SaveArticle;
use App\Domain\Pricing\DocumentPricing;
use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\RowPriceSnapshot;
use App\Domain\Units\UnitRegister;
use App\Models\Account;
use App\Models\Article;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\Project;
use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Turns rows typed on an invoice, quote or order into row attributes: an
 * optional article, a description, quantity, unit, price excluding VAT,
 * discount, VAT rate and revenue account (the chosen one, the article's,
 * or the default for the VAT rate). The unit is one of the company's
 * register (ADR-071 §12.9): by id, or by a code or alias the register
 * knows; any other text is refused. Must run inside the company.
 *
 * Cost centre and project (ADR-081 gap 6) are given by code, looked up
 * inside the company (ADR-009), so a row can never point at another
 * company's object. A row that gives the key (even empty, for none) keeps
 * what it says; a row without the key takes the article's default, else
 * the customer's, field by field; a row that then has a project but no
 * cost centre takes the project's default cost centre. Deactivated objects
 * stay valid, so a credit invoice can copy an old row.
 *
 * With a price context (ADR-082 P3), the article rows are priced by the
 * price engine in one call for the whole document (DocumentPricing): a row
 * without a price gets the resolved one, and every article row stores its
 * price source and explanation. A row may say price_source MANUAL (the
 * person typed the price) to keep its price as typed; any explanation sent
 * with a row is ignored, it is always worked out here.
 */
final class SalesRowInput
{
    /**
     * @param  list<array<string, mixed>>  $input  article (number), description, quantity, unit_id (or unit: a code of the register), price (kronor; empty on an article row with a price context: the resolved price), discount (percent), vat_rate, account (number), cost_center and project (codes), price_source (MANUAL keeps a typed price)
     * @param  list<RowPriceSnapshot>  $previous  rows already priced on the document, or on the quote or order it is made from
     * @return list<array{article_id: ?int, description: string, quantity: string, unit: string, unit_id: int, unit_price_ore: int, discount_bp: int, vat_rate: int, account_id: int, net_ore: int, cost_center_id: ?int, project_id: ?int, price_source?: ?string, price_explanation?: ?array<string, mixed>}>
     *
     * @throws ValidationException
     */
    public static function parse(array $input, bool $negate = false, ?Customer $customer = null, ?PriceContext $pricing = null, array $previous = []): array
    {
        $articles = Article::query()->whereIn('number', array_filter(array_map(fn (array $row): string => trim((string) ($row['article'] ?? '')), $input)))->get()->keyBy('number');
        $costCenters = self::objects(CostCenter::class, $input, 'cost_center');
        $projects = self::objects(Project::class, $input, 'project');
        /** @var array<int, int>|null $projectCostCenters loaded once, when a row needs a project's cost centre */
        $projectCostCenters = null;
        $accountNumbers = array_filter(array_map(fn (array $row): int => (int) ($row['account'] ?? 0), $input));
        $accounts = Account::query()
            ->where(fn ($query) => $query
                ->whereIn('number', [...$accountNumbers, ...array_values(SaveArticle::DEFAULT_ACCOUNTS)])
                ->orWhereIn('id', $articles->pluck('account_id')))
            ->get();
        $byNumber = $accounts->keyBy('number');
        $byId = $accounts->keyBy('id');
        $units = UnitRegister::load();
        $errors = [];
        $rows = [];
        /** @var array<int, array{article_id: int, unit_id: ?int, quantity: string, price_minor: ?int, discount_bp: ?int, hint: ?string, vat_rate: int}> $toPrice by position in $rows */
        $toPrice = [];
        /** @var array<int, int> $lines input index by position in $rows */
        $lines = [];

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

            $priceGiven = trim((string) ($row['price'] ?? '')) !== '';

            try {
                $price = Money::parse($priceGiven ? (string) $row['price'] : '0');
            } catch (InvalidArgumentException) {
                $errors["rows.{$index}.price"] = __('Rad :line: priset går inte att läsa. Skriv till exempel 149,00.', ['line' => $line]);

                continue;
            }

            $discount = InvoiceCalculator::discount((string) ($row['discount'] ?? ''));

            if ($discount === null) {
                $errors["rows.{$index}.discount"] = __('Rad :line: rabatten ska vara mellan 0 och 100 procent.', ['line' => $line]);

                continue;
            }

            try {
                $unit = $units->forRow($row['unit_id'] ?? null, $row['unit'] ?? null, $article, "rows.{$index}.unit_id");
            } catch (ValidationException $exception) {
                $errors["rows.{$index}.unit_id"] = __('Rad :line: :message', ['line' => $line, 'message' => $exception->validator->errors()->first()]);

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

            [$costCenterId, $projectId] = [
                self::dimension($row, 'cost_center', $costCenters, $article->default_cost_center_id ?? $customer?->default_cost_center_id),
                self::dimension($row, 'project', $projects, $article->default_project_id ?? $customer?->default_project_id),
            ];

            if ($costCenterId === false) {
                $errors["rows.{$index}.cost_center"] = __('Rad :line: kostnadsstället :code finns inte.', ['line' => $line, 'code' => self::code($row['cost_center'] ?? null)]);

                continue;
            }

            if ($projectId === false) {
                $errors["rows.{$index}.project"] = __('Rad :line: projektet :code finns inte.', ['line' => $line, 'code' => self::code($row['project'] ?? null)]);

                continue;
            }

            // A row that does not name a cost centre, and gets none from the article or the customer, takes its project's.
            if ($costCenterId === null && $projectId !== null && ! array_key_exists('cost_center', $row)) {
                $projectCostCenters ??= Project::query()->whereNotNull('default_cost_center_id')->pluck('default_cost_center_id', 'id')->map(fn (mixed $id): int => (int) $id)->all();
                $costCenterId = $projectCostCenters[$projectId] ?? null;
            }

            if ($pricing !== null && $article !== null) {
                $toPrice[count($rows)] = [
                    'article_id' => $article->id,
                    'unit_id' => $unit->id,
                    'quantity' => $quantity,
                    'price_minor' => $priceGiven ? $price->ore : null,
                    'discount_bp' => trim((string) ($row['discount'] ?? '')) === '' ? null : $discount,
                    'hint' => is_string($row['price_source'] ?? null) && $row['price_source'] !== '' ? $row['price_source'] : null,
                    'vat_rate' => $rate,
                ];
            }

            $lines[count($rows)] = $index;

            $rows[] = [
                'article_id' => $article?->id,
                'description' => trim((string) ($row['description'] ?? '')),
                'quantity' => $quantity,
                'unit' => $units->snapshot($unit, $article, $row['unit'] ?? null),
                'unit_id' => $unit->id,
                'unit_price_ore' => $price->ore,
                'discount_bp' => $discount,
                'vat_rate' => $rate,
                'account_id' => $account->id,
                'net_ore' => InvoiceCalculator::rowNet($price, $quantity, $discount)->ore,
                'cost_center_id' => $costCenterId,
                'project_id' => $projectId,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $pricing === null ? $rows : self::priced($rows, $toPrice, $lines, $pricing, $previous);
    }

    /**
     * The rows with the price engine's prices (ADR-082 P3): every article
     * row gets its price, discount, source and explanation; a row without
     * an article keeps what was typed and has no source.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, array{article_id: int, unit_id: ?int, quantity: string, price_minor: ?int, discount_bp: ?int, hint: ?string, vat_rate: int}>  $toPrice
     * @param  array<int, int>  $lines
     * @param  list<RowPriceSnapshot>  $previous
     * @return list<array{article_id: ?int, description: string, quantity: string, unit: string, unit_id: int, unit_price_ore: int, discount_bp: int, vat_rate: int, account_id: int, net_ore: int, cost_center_id: ?int, project_id: ?int, price_source?: ?string, price_explanation?: ?array<string, mixed>}>
     *
     * @throws ValidationException
     */
    private static function priced(array $rows, array $toPrice, array $lines, PriceContext $pricing, array $previous): array
    {
        $prices = app(DocumentPricing::class)->price($pricing, $toPrice, $previous);
        $errors = [];

        foreach ($rows as $position => $row) {
            if (! array_key_exists($position, $prices)) {
                $rows[$position] = [...$row, 'price_source' => null, 'price_explanation' => null];

                continue;
            }

            $price = $prices[$position];

            if ($price === null) {
                $index = $lines[$position];
                $errors["rows.{$index}.price"] = __('Rad :line: det finns inget pris för artikeln i :currency. Skriv ett pris.', ['line' => $index + 1, 'currency' => $pricing->currency]);

                continue;
            }

            $rows[$position] = [
                ...$row,
                'unit_price_ore' => $price['unit_price_minor'],
                'discount_bp' => $price['discount_bp'],
                'net_ore' => InvoiceCalculator::rowNet(Money::ofOre($price['unit_price_minor']), (string) $row['quantity'], $price['discount_bp'])->ore,
                'price_source' => $price['source']->value,
                'price_explanation' => $price['explanation'],
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var list<array{article_id: ?int, description: string, quantity: string, unit: string, unit_id: int, unit_price_ore: int, discount_bp: int, vat_rate: int, account_id: int, net_ore: int, cost_center_id: ?int, project_id: ?int, price_source?: ?string, price_explanation?: ?array<string, mixed>}> $rows */
        return $rows;
    }

    /**
     * The objects the rows name by code, keyed by code; one query.
     *
     * @param  class-string<CostCenter|Project>  $model
     * @param  list<array<string, mixed>>  $input
     * @return array<string, int>
     */
    private static function objects(string $model, array $input, string $key): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn (array $row): string => self::code($row[$key] ?? null), $input))));

        if ($codes === []) {
            return [];
        }

        /** @var array<string, int> $ids */
        $ids = $model::query()->whereIn('code', $codes)->pluck('id', 'code')->map(fn (mixed $id): int => (int) $id)->all();

        return $ids;
    }

    /**
     * The object id a row gets: what it names (null for none, false for a
     * code the company does not have), else the default.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $objects
     */
    private static function dimension(array $row, string $key, array $objects, ?int $default): int|false|null
    {
        if (! array_key_exists($key, $row)) {
            return $default;
        }

        if ($row[$key] !== null && ! is_scalar($row[$key])) {
            return false;
        }

        $code = trim((string) $row[$key]);

        if ($code === '') {
            return null;
        }

        return $objects[$code] ?? false;
    }

    /** A code as typed, for an error message; anything but text shows as nothing. */
    private static function code(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
