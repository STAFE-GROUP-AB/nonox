<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Domain\Pricing\ArticleCostBook;
use App\Domain\Pricing\DocumentPricing;
use App\Domain\Pricing\PriceChannel;
use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\PriceListKind;
use App\Domain\Pricing\PriceRequest;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Units\ArticleUnits;
use App\Domain\Units\UnitRegister;
use App\Models\Article;
use App\Models\Customer;
use App\Models\PriceList;
use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Support\ApiInput;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Which price does this customer get?" over the API (ADR-082 P9): the same
 * PriceResolver as the invoice and quote editors, asked once for all rows
 * (at most 500), so a web shop or till outside NONOX shows the price the
 * invoice will carry. Read with prices:view; the article's cost is added only
 * for a credential that also has prices:view-cost, and only in kronor.
 */
final class PriceResolveController
{
    use ActsAsCredential;

    public const int ROW_LIMIT = 500;

    /**
     * rows[]=article:quantity:unit, where the article is its number, the
     * quantity defaults to 1 and the unit to the article's sales unit.
     */
    public function __invoke(Request $request, PriceResolver $resolver): JsonResponse
    {
        $company = $this->company();
        Gate::forUser($this->user())->authorize('viewAny', [PriceList::class, $company]);

        $input = Validator::make($request->query(), [
            'customer' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'regex:/^[A-Za-z]{3}$/'],
            'channel' => ['nullable', Rule::enum(PriceChannel::class)],
            'channel_list' => ['nullable', 'integer'],
            'rows' => ['required', 'array', 'min:1', 'max:'.self::ROW_LIMIT],
            'rows.*' => ['required', 'string', 'max:200'],
        ], [
            'rows.required' => __('Skicka minst en rad som rows[]=artikelnummer:antal:enhet.'),
            'rows.max' => __('Högst :limit rader i taget.', ['limit' => self::ROW_LIMIT]),
        ])->validate();

        $customer = isset($input['customer']) ? Customer::query()->findOrFail((int) $input['customer']) : null;
        $channelList = isset($input['channel_list'])
            ? PriceList::query()->whereKey((int) $input['channel_list'])->where('kind', PriceListKind::Channel->value)->firstOrFail()
            : null;
        $date = isset($input['date']) ? CarbonImmutable::parse((string) $input['date']) : CarbonImmutable::today();
        $currency = strtoupper((string) ($input['currency'] ?? 'SEK'));
        $channel = PriceChannel::tryFrom((string) ($input['channel'] ?? '')) ?? PriceChannel::Sales;

        $parsed = array_map(fn (string $row): array => $this->parse($row), array_values((array) $input['rows']));
        $articles = Article::query()->whereIn('number', array_values(array_unique(array_column($parsed, 'article'))))->get()->keyBy('number');
        $units = UnitRegister::load();

        $requests = [];
        $errors = [];
        $lines = [];

        foreach ($parsed as $index => $row) {
            $article = $articles->get($row['article']);
            $unit = $row['unit'] === null ? null : $units->match($row['unit']);

            if (! $article instanceof Article) {
                $errors["rows.{$index}"] = __('Artikeln :number finns inte.', ['number' => $row['article']]);

                continue;
            }

            if ($row['unit'] !== null && $unit === null) {
                $errors["rows.{$index}"] = __('Enheten :unit finns inte.', ['unit' => $row['unit']]);

                continue;
            }

            $requests[] = new PriceRequest("r{$index}", $article->id, $row['quantity_milli'], $unit?->id);
            $lines[$index] = ['article' => $article, 'unit_code' => $unit === null ? $this->salesUnitCode($article, $units) : $unit->code, 'quantity' => $row['quantity']];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $resolved = $resolver->resolveMany(new PriceContext($company->id, $date, $currency, $customer?->id, $channel, $channelList?->id), $requests);
        $costs = $this->mayViewCost() && $currency === 'SEK' ? ArticleCostBook::for($company, array_values(array_map(fn (array $line): int => $line['article']->id, $lines)), $date) : null;

        $rows = [];

        foreach ($lines as $index => $resolvedLine) {
            $article = $resolvedLine['article'];
            $unitCode = $resolvedLine['unit_code'];
            $price = $resolved["r{$index}"];

            $line = [
                'row' => $index,
                'article' => $article->number,
                'article_id' => $article->id,
                'quantity' => $resolvedLine['quantity'],
                'unit' => $unitCode,
                'unit_price_minor' => $price->unitPriceMinor,
                'discount_bp' => $price->discountBp,
                'currency' => $price->currency,
                'price_source' => $price->source?->value,
                'prices_include_vat' => $price->includesVat,
                'gross_unit_price_minor' => $price->grossUnitPriceMinor,
                'explanation' => DocumentPricing::explanation($price),
                'warnings' => $price->warnings,
            ];

            if ($costs !== null) {
                $line['unit_cost_minor'] = $costs->unitCostOre($article->id, $unitCode);
            }

            $rows[] = $line;
        }

        return CursorList::one([
            'customer_id' => $customer?->id,
            'date' => $date->toDateString(),
            'currency' => $currency,
            'channel' => $channel->value,
            'rows' => $rows,
        ]);
    }

    private function mayViewCost(): bool
    {
        return $this->user()->hasTeamPermission($this->company(), 'prices:view-cost');
    }

    private function salesUnitCode(Article $article, UnitRegister $units): string
    {
        $id = $article->sales_unit_id ?? $article->stock_unit_id;

        return ($id === null ? null : $units->find($id)?->code) ?? ArticleUnits::stockCode($article);
    }

    /** @return array{article: string, quantity: string, quantity_milli: int, unit: ?string} */
    private function parse(string $row): array
    {
        $parts = explode(':', $row, 3);
        $article = trim($parts[0]);
        $quantity = trim($parts[1] ?? '') === '' ? '1' : trim($parts[1]);
        $unit = trim($parts[2] ?? '') === '' ? null : trim($parts[2]);

        if ($article === '') {
            throw ValidationException::withMessages(['rows' => __('Skriv varje rad som artikelnummer:antal:enhet.')]);
        }

        $milli = DocumentPricing::milli(ApiInput::quantity($quantity, 'rows'));

        return ['article' => $article, 'quantity' => $quantity, 'quantity_milli' => $milli, 'unit' => $unit];
    }
}
