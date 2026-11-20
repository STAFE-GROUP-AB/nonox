<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Invoicing\SaveArticle;
use App\Domain\Pricing\PriceChannel;
use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\PriceRequest;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\ResolvedPrice;
use App\Domain\Units\ArticleUnits;
use App\Models\Article;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPriceGroup;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceListPriceGroup;
use App\Models\Unit;
use App\Models\User;
use App\Support\Currency\ExchangeRate;
use App\Support\Currency\RiksbankRates;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigRational;

/**
 * Builds a synthetic pricing world from a fixture (tests/Fixtures/pricing/*.json)
 * and resolves its rows (ADR-082 P2). Fixtures name everything by code
 * (articles, lists, groups, agreements, items as "LIST#0"), so expected
 * results never depend on database ids.
 */
final class PricingScenario
{
    /** @var array<string, int> */
    public array $articles = [];

    /** @var array<string, int> */
    public array $lists = [];

    /** @var array<string, int> */
    public array $groups = [];

    /** @var array<string, int> */
    public array $agreements = [];

    /** @var array<string, int> "LIST#0" / "PA1#0" => item id */
    public array $items = [];

    public ?Customer $customer = null;

    /** @param  array<string, mixed>  $fixture */
    private function __construct(public User $owner, public Company $company, public array $fixture) {}

    /** @return array<string, mixed> */
    public static function fixture(string $name): array
    {
        $json = file_get_contents(base_path("tests/Fixtures/pricing/{$name}.json"));

        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $fixture */
    public static function build(array $fixture, ?User $owner = null, ?Company $company = null): self
    {
        if ($owner === null || $company === null) {
            [$owner, $company] = Bookkeeping::company();
        }

        CurrentCompany::set($company);
        $scenario = new self($owner, $company, $fixture);
        $scenario->fakeRate($fixture['rate'] ?? null);

        foreach ($fixture['articles'] ?? [] as $number => $article) {
            $scenario->articles[$number] = $scenario->article((string) $number, $article)->id;
        }

        foreach ($fixture['lists'] ?? [] as $code => $list) {
            $scenario->lists[$code] = PriceList::create([
                'code' => $code,
                'name' => "Lista {$code}",
                'kind' => $list['kind'] ?? 'STANDARD',
                'currency' => $list['currency'] ?? 'SEK',
                'prices_include_vat' => $list['prices_include_vat'] ?? false,
                'valid_from' => $list['valid_from'] ?? null,
                'valid_to' => $list['valid_to'] ?? null,
                'is_default_for_currency' => $list['default'] ?? false,
                'derived_percent_bp' => $list['derived_percent_bp'] ?? null,
                'rounding_rule' => $list['rounding'] ?? 'NONE',
                'channel' => $list['channel'] ?? null,
                'archived_at' => $list['archived_at'] ?? null,
                'created_by' => $owner->id,
            ])->id;
        }

        foreach ($fixture['lists'] ?? [] as $code => $list) {
            if (isset($list['based_on'])) {
                PriceList::query()->whereKey($scenario->lists[$code])->update(['based_on_list_id' => $scenario->lists[$list['based_on']]]);
            }

            foreach ($list['items'] ?? [] as $index => $item) {
                $scenario->items["{$code}#{$index}"] = PriceListItem::create([
                    'price_list_id' => $scenario->lists[$code],
                    ...$scenario->itemAttributes($item),
                    'valid_from' => $item['valid_from'] ?? null,
                    'valid_to' => $item['valid_to'] ?? null,
                ])->id;
            }
        }

        foreach ($fixture['groups'] ?? [] as $code => $group) {
            $scenario->groups[$code] = CustomerPriceGroup::create([
                'code' => $code,
                'name' => "Grupp {$code}",
                'price_list_id' => isset($group['list']) ? $scenario->lists[$group['list']] : null,
            ])->id;
        }

        foreach ($fixture['lists'] ?? [] as $code => $list) {
            foreach ($list['groups'] ?? [] as $group) {
                PriceListPriceGroup::create(['price_list_id' => $scenario->lists[$code], 'customer_price_group_id' => $scenario->groups[$group]]);
            }
        }

        if (isset($fixture['customer'])) {
            $customer = Crm::customer($company, $owner, 'Kunden AB');
            $customer->forceFill([
                'price_group_id' => isset($fixture['customer']['group']) ? $scenario->groups[$fixture['customer']['group']] : null,
                'price_list_id' => isset($fixture['customer']['list']) ? $scenario->lists[$fixture['customer']['list']] : null,
            ])->save();
            $scenario->customer = $customer;

            foreach ($fixture['agreements'] ?? [] as $code => $agreement) {
                $scenario->agreements[$code] = PriceAgreement::create([
                    'number' => count($scenario->agreements) + 1,
                    'customer_id' => $customer->id,
                    'title' => "Avtal {$code}",
                    'currency' => $agreement['currency'] ?? 'SEK',
                    'valid_from' => $agreement['valid_from'],
                    'valid_to' => $agreement['valid_to'] ?? null,
                    'status' => 'DRAFT',
                    'allow_campaigns' => $agreement['allow_campaigns'] ?? false,
                    'terminated_on' => $agreement['terminated_on'] ?? null,
                    'created_by' => $owner->id,
                ])->id;

                foreach ($agreement['items'] ?? [] as $index => $item) {
                    $scenario->items["{$code}#{$index}"] = PriceAgreementItem::create([
                        'price_agreement_id' => $scenario->agreements[$code],
                        ...$scenario->itemAttributes($item),
                        'article_id' => isset($item['article']) ? $scenario->articles[$item['article']] : null,
                        'applies_to_all' => ! isset($item['article']),
                        'base_price_list_id' => isset($item['base_list']) ? $scenario->lists[$item['base_list']] : null,
                    ])->id;
                }

                // A draft's terms are added first; once it has left draft they are frozen (ADR-082 P5).
                PriceAgreement::query()->whereKey($scenario->agreements[$code])->update(['status' => $agreement['status'] ?? 'ACTIVE']);
            }
        }

        return $scenario;
    }

    /**
     * @param  array<string, mixed>  $context  date, currency, channel
     * @param  list<PriceRequest>  $requests
     * @return array<string, ResolvedPrice>
     */
    public function resolve(array $context, array $requests): array
    {
        return app(PriceResolver::class)->resolveMany($this->context($context), $requests);
    }

    /** @param  array<string, mixed>  $context */
    public function context(array $context): PriceContext
    {
        return new PriceContext(
            companyId: (int) $this->company->id,
            date: (string) $context['date'],
            currency: (string) ($context['currency'] ?? 'SEK'),
            customerId: ($context['no_customer'] ?? false) ? null : $this->customer?->id,
            channel: PriceChannel::from((string) ($context['channel'] ?? 'SALES')),
        );
    }

    /** @param  array<string, mixed>  $row */
    public function request(array $row): PriceRequest
    {
        return new PriceRequest(
            key: (string) $row['key'],
            articleId: $this->articles[$row['article']],
            quantityMilli: (int) ($row['quantity_milli'] ?? 1000),
            unitId: isset($row['unit']) ? $this->unitId((string) $row['unit']) : null,
            manualPriceMinor: $row['manual_price_minor'] ?? null,
            manualDiscountBp: $row['manual_discount_bp'] ?? null,
        );
    }

    public function unitId(string $code): int
    {
        return (int) Unit::query()->where('code', $code)->value('id');
    }

    /**
     * The result in the fixture's own names, for comparison with "expected".
     *
     * @return array<string, mixed>
     */
    public function describe(ResolvedPrice $price): array
    {
        $explanation = $price->explanation;

        return array_filter([
            'unit_price_minor' => $price->unitPriceMinor,
            'discount_bp' => $price->discountBp,
            'source' => $price->source?->value,
            'list' => isset($explanation['list_id']) ? array_search($explanation['list_id'], $this->lists, true) : null,
            'agreement' => isset($explanation['agreement_id']) ? array_search($explanation['agreement_id'], $this->agreements, true) : null,
            'item' => isset($explanation['item_id']) ? $this->itemName((int) $explanation['item_id'], $explanation) : null,
            'tier_min_milli' => $explanation['tier_min_milli'] ?? null,
            'base_minor' => $explanation['base_minor'] ?? null,
            'gross_minor' => $price->grossUnitPriceMinor,
            'derived' => $explanation['derived'] ?? null,
            'rate_micros' => $explanation['rate_micros'] ?? null,
            'folded_discount_bp' => $explanation['folded_discount_bp'] ?? null,
            'manual_discount_bp' => $explanation['manual_discount_bp'] ?? null,
            'considered' => $price->considered === [] ? null : array_map(fn (array $entry): array => $this->describeEntry($entry), $price->considered),
            'warnings' => $price->warnings,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * A considered candidate in the fixture's names.
     *
     * @param  array<string, int|string|bool|null>  $entry
     * @return array<string, mixed>
     */
    private function describeEntry(array $entry): array
    {
        return array_filter([
            'source' => $entry['source'] ?? null,
            'list' => isset($entry['list_id']) ? (array_search($entry['list_id'], $this->lists, true) ?: 'foreign') : null,
            'agreement' => isset($entry['agreement_id']) ? array_search($entry['agreement_id'], $this->agreements, true) : null,
            'item' => isset($entry['item_id']) ? $this->itemName((int) $entry['item_id'], $entry) : null,
            'unit_price_minor' => $entry['unit_price_minor'] ?? null,
            'discount_bp' => $entry['discount_bp'] ?? null,
            'reason' => $entry['reason'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @param  array<string, mixed>  $explanation */
    private function itemName(int $id, array $explanation): ?string
    {
        foreach ($this->items as $name => $itemId) {
            $owner = explode('#', $name)[0];
            $isAgreementItem = isset($this->agreements[$owner]);

            if ($itemId === $id && $isAgreementItem === isset($explanation['agreement_id'])) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function itemAttributes(array $item): array
    {
        return [
            'article_id' => isset($item['article']) ? $this->articles[$item['article']] : null,
            'unit_id' => isset($item['unit']) ? $this->unitId((string) $item['unit']) : null,
            'min_quantity_milli' => $item['min_quantity_milli'] ?? 0,
            'price_minor' => $item['price_minor'] ?? null,
            'discount_bp' => $item['discount_bp'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $article */
    private function article(string $number, array $article): Article
    {
        $ore = (int) $article['price_ore'];

        $saved = app(SaveArticle::class)($this->owner, $this->company, [
            'number' => $number,
            'name' => "Artikel {$number}",
            'unit' => $article['unit'] ?? 'st',
            'price' => intdiv($ore, 100).'.'.str_pad((string) ($ore % 100), 2, '0', STR_PAD_LEFT),
            'vat_rate' => $article['vat_rate'] ?? 2500,
        ]);

        foreach ($article['conversions'] ?? [] as $unit => $perStock) {
            ArticleUnits::saveManual($saved->load('stockUnit'), (string) $unit, BigRational::of((string) $perStock));
        }

        return $saved;
    }

    /** @param  ?array<string, mixed>  $rate */
    private function fakeRate(?array $rate): void
    {
        app()->instance(RiksbankRates::class, new class($rate) extends RiksbankRates
        {
            public int $calls = 0;

            /** @param  ?array<string, mixed>  $rate */
            public function __construct(private readonly ?array $fixed) {}

            public function rate(string $currency, string $date): ?ExchangeRate
            {
                $this->calls++;

                if ($this->fixed === null || strtoupper($currency) !== $this->fixed['currency']) {
                    return null;
                }

                return new ExchangeRate($this->fixed['currency'], (string) $this->fixed['date'], (int) $this->fixed['micros'], 'riksbank');
            }
        });
    }
}
