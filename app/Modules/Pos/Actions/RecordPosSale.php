<?php

declare(strict_types=1);

namespace App\Modules\Pos\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Pos\Domain\Sale;
use App\Modules\Pos\Domain\SaleRow;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosProductLink;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleRow;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a till sale exactly once and takes its goods out of Lager
 * (ADR-042). The sale row is inserted first, inside the transaction, with
 * the unique key (company, provider, the till's id): a replayed webhook, the
 * nightly fetch or two deliveries at the same moment find the key taken and
 * change nothing, so stock is never moved twice.
 *
 * Each goods line is matched to an article: the till product's link, else
 * GTIN, scanner code, SKU or article number. A matched line becomes a
 * POS_SALE movement from the connection's stock location (else the
 * article's default location); a line without an article, without a
 * location or without enough stock (where negative stock is off) waits
 * under Omatchade for a person (LinkPosSaleRow).
 */
class RecordPosSale
{
    public function __construct(private readonly StockLedger $ledger) {}

    /** The recorded sale, or null when it was already recorded. */
    public function __invoke(PosConnection $connection, Sale $sale, string $source): ?PosSale
    {
        $company = Company::query()->findOrFail($connection->company_id);

        return CurrentCompany::run($company, fn (): ?PosSale => DB::transaction(function () use ($company, $connection, $sale, $source): ?PosSale {
            $payload = (string) json_encode($sale->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $inserted = DB::table('pos_sales')->insertOrIgnore([
                'company_id' => $company->id,
                'pos_connection_id' => $connection->id,
                'provider' => $connection->provider->value,
                'external_id' => mb_substr($sale->externalId, 0, 150),
                'source' => $source,
                'is_refund' => $sale->isRefund,
                'sold_at' => $sale->soldAt->utc(),
                'business_date' => $sale->businessDate(),
                'currency' => $sale->currency,
                'total_ore' => $sale->totalOre,
                'vat_ore' => $sale->vatOre,
                'tips_ore' => $sale->tipsOre,
                'vat_totals' => json_encode((object) $sale->vatTotals),
                'payments' => json_encode((object) $sale->payments),
                'warnings' => json_encode($sale->warnings),
                'payload' => $payload,
                'payload_hash' => hash('sha256', $payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return null;
            }

            $record = PosSale::query()
                ->where('provider', $connection->provider->value)
                ->where('external_id', mb_substr($sale->externalId, 0, 150))
                ->firstOrFail();

            $articles = $this->articles($connection, $sale->rows);
            $matched = 0;
            $unmatched = 0;

            foreach ($sale->rows as $row) {
                $article = $row->isGoods ? $this->match($row, $articles) : null;
                $line = PosSaleRow::create([
                    'pos_sale_id' => $record->id,
                    'line_no' => $row->lineNo,
                    'external_product_id' => $row->externalProductId,
                    'external_variant_id' => $row->externalVariantId,
                    'barcode' => $row->barcode,
                    'sku' => $row->sku,
                    'name' => $row->name,
                    'quantity_milli' => $row->quantityMilli,
                    'amount_ore' => $row->amountOre,
                    'vat_rate' => $row->vatRate,
                    'status' => $row->isGoods && $row->quantityMilli !== 0 ? PosSaleRow::UNMATCHED : PosSaleRow::SKIPPED,
                    'article_id' => $article?->id,
                ]);

                if ($line->status === PosSaleRow::SKIPPED) {
                    continue;
                }

                if ($article instanceof Article && $this->post($company, $connection, $record, $line, $article)) {
                    $matched++;

                    continue;
                }

                $unmatched++;
            }

            $record->forceFill(['matched_count' => $matched, 'unmatched_count' => $unmatched])->save();

            Audit::record('pos.sale_recorded', $record, [
                'provider' => $connection->provider->value,
                'external_id' => $record->external_id,
                'total_ore' => $record->total_ore,
                'matched' => $matched,
                'unmatched' => $unmatched,
            ], $company);

            return $record;
        }));
    }

    /**
     * Posts the line's stock movement. Returns false (the line stays
     * unmatched) when the article is not kept in stock, no location is
     * known, or the stock does not allow it.
     */
    public function post(Company $company, PosConnection $connection, PosSale $sale, PosSaleRow $line, Article $article): bool
    {
        if (! $article->is_stock_item) {
            $line->forceFill(['article_id' => $article->id, 'status' => PosSaleRow::SKIPPED])->save();

            return true;
        }

        $location = $connection->location_id ?? $article->default_location_id;

        if ($location === null) {
            return false;
        }

        try {
            $movements = DB::transaction(fn (): array => $this->ledger->post(
                $company,
                null,
                MovementType::PosSale,
                [['article_id' => $article->id, 'location_id' => $location, 'quantity_milli' => -$line->quantity_milli]],
                CarbonImmutable::parse($sale->business_date->toDateString()),
                $sale,
                $connection->provider->label().' '.$sale->external_id,
            ));
        } catch (ValidationException) {
            return false;
        }

        $line->forceFill([
            'article_id' => $article->id,
            'status' => PosSaleRow::MATCHED,
            'stock_movement_id' => $movements[0]->id ?? null,
        ])->save();

        return true;
    }

    /**
     * The articles the lines can match, in two queries: the links of the
     * till's products, and the articles with one of the lines' codes.
     *
     * @param  list<SaleRow>  $rows
     * @return array{links: array<string, int>, articles: Collection<int, Article>}
     */
    private function articles(PosConnection $connection, array $rows): array
    {
        $productIds = array_values(array_filter(array_map(fn (SaleRow $row): ?string => $row->externalProductId, $rows)));
        $codes = array_values(array_unique(array_filter(array_merge(
            array_map(fn (SaleRow $row): ?string => $row->barcode, $rows),
            array_map(fn (SaleRow $row): ?string => $row->sku, $rows),
        ))));

        $links = $productIds === [] ? [] : PosProductLink::query()
            ->where('pos_connection_id', $connection->id)
            ->whereIn('external_product_id', $productIds)
            ->pluck('article_id', 'external_product_id')
            ->all();

        $articles = Article::query()
            ->where(function ($query) use ($links, $codes): void {
                $query->whereKey(array_values($links));

                if ($codes !== []) {
                    $query->orWhereIn('gtin', $codes)->orWhereIn('scanner_code', $codes)->orWhereIn('sku', $codes)->orWhereIn('number', $codes);
                }
            })
            ->where('is_active', true)
            ->get();

        /** @var array<string, int> $links */
        return ['links' => $links, 'articles' => $articles];
    }

    /** @param  array{links: array<string, int>, articles: Collection<int, Article>}  $candidates */
    private function match(SaleRow $row, array $candidates): ?Article
    {
        $articles = $candidates['articles'];

        if ($row->externalProductId !== null && isset($candidates['links'][$row->externalProductId])) {
            $linked = $articles->firstWhere('id', $candidates['links'][$row->externalProductId]);

            if ($linked instanceof Article) {
                return $linked;
            }
        }

        $checks = [
            ['gtin', $row->barcode],
            ['scanner_code', $row->barcode],
            ['sku', $row->sku],
            ['number', $row->sku],
        ];

        foreach ($checks as [$field, $code]) {
            if ($code === null) {
                continue;
            }

            $found = $articles->first(fn (Article $article): bool => $article->getAttribute($field) === $code);

            if ($found instanceof Article) {
                return $found;
            }
        }

        return null;
    }
}
