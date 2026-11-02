<?php

declare(strict_types=1);

namespace App\Modules\Pos\Actions;

use App\Models\Article;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosProductLink;
use App\Modules\Pos\Support\ArticlePayload;
use App\Modules\Pos\Support\PosApiFailed;
use App\Modules\Pos\Support\PosDrivers;
use App\Support\Tenancy\CurrentCompany;

/**
 * Articles out to the till (ADR-042). With two-way sync the till's own
 * products are first linked to articles with the same GTIN, scanner code,
 * SKU or number, so they are updated instead of created twice. Then every
 * active article whose content changed since it was last sent is created or
 * updated in the till, at most BUDGET per run; the job runs again for the
 * rest. Articles are never deleted in the till.
 */
class SyncProducts
{
    public const int BUDGET = 200;

    /**
     * @param  ?list<int>  $articleIds  only these, else every active article
     * @return array{sent: int, failed: int, linked: int, more: bool}
     */
    public function __invoke(PosConnection $connection, ?array $articleIds = null): array
    {
        return CurrentCompany::run($connection->company_id, function () use ($connection, $articleIds): array {
            $result = ['sent' => 0, 'failed' => 0, 'linked' => 0, 'more' => false];

            if (! $connection->isActive() || ! $connection->pushesProducts()) {
                return $result;
            }

            $driver = PosDrivers::for($connection->provider);

            if ($connection->syncDirection() === PosConnection::TWO_WAY && $articleIds === null) {
                $result['linked'] = $this->linkExisting($connection);
            }

            $links = PosProductLink::query()->where('pos_connection_id', $connection->id)->get()->keyBy('article_id');
            $articles = Article::query()
                ->where('is_active', true)
                ->when($articleIds !== null, fn ($query) => $query->whereKey($articleIds))
                ->orderBy('id')
                ->get()
                ->filter(fn (Article $article): bool => $links->get($article->id)?->pushed_hash !== ArticlePayload::hash($article))
                ->values();

            foreach ($articles->take(self::BUDGET) as $article) {
                $link = $links->get($article->id);

                try {
                    $ids = $driver->pushProduct($connection, $article, $link);
                } catch (PosApiFailed $exception) {
                    $result['failed']++;
                    $link?->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();

                    if ($exception->retryable() || $exception->credentialsRejected) {
                        throw $exception;
                    }

                    $connection->log('warning', str_starts_with($exception->getMessage(), 'No product group')
                        ? __(':article skickades inte: välj en varugrupp för momssatsen :rate % under Kassa.', ['article' => $article->number, 'rate' => intdiv($article->vat_rate, 100)])
                        : __(':article kunde inte skickas till kassan.', ['article' => $article->number]), ['status' => $exception->status]);

                    continue;
                }

                PosProductLink::query()->updateOrCreate(
                    ['pos_connection_id' => $connection->id, 'article_id' => $article->id],
                    [
                        'external_product_id' => $ids['product_id'],
                        'external_variant_id' => $ids['variant_id'],
                        'pushed_hash' => ArticlePayload::hash($article),
                        'synced_at' => now(),
                        'last_error' => null,
                    ],
                );
                $result['sent']++;
            }

            $result['more'] = $articles->count() > self::BUDGET;

            if ($result['sent'] > 0 || $result['linked'] > 0) {
                $connection->forceFill(['last_synced_at' => now()])->save();
                $connection->log('info', __(':sent artiklar skickades till kassan och :linked kopplades till kassans produkter.', ['sent' => $result['sent'], 'linked' => $result['linked']]));
            }

            return $result;
        });
    }

    /** Links the till's products to articles with the same codes. */
    private function linkExisting(PosConnection $connection): int
    {
        $products = PosDrivers::for($connection->provider)->listProducts($connection);
        $linkedProducts = PosProductLink::query()->where('pos_connection_id', $connection->id)->pluck('external_product_id')->all();
        $linkedArticles = PosProductLink::query()->where('pos_connection_id', $connection->id)->pluck('article_id')->all();
        $codes = array_values(array_unique(array_filter(array_merge(array_column($products, 'barcode'), array_column($products, 'sku')))));

        if ($codes === []) {
            return 0;
        }

        $articles = Article::query()->where('is_active', true)
            ->where(fn ($query) => $query->whereIn('gtin', $codes)->orWhereIn('scanner_code', $codes)->orWhereIn('sku', $codes)->orWhereIn('number', $codes))
            ->get();
        $linked = 0;

        foreach ($products as $product) {
            if (in_array($product['product_id'], $linkedProducts, true)) {
                continue;
            }

            $article = $articles->first(fn (Article $article): bool => ! in_array($article->id, $linkedArticles, true) && (
                ($product['barcode'] !== null && ($article->gtin === $product['barcode'] || $article->scanner_code === $product['barcode']))
                || ($product['sku'] !== null && ($article->sku === $product['sku'] || $article->number === $product['sku']))
            ));

            if (! $article instanceof Article) {
                continue;
            }

            PosProductLink::create([
                'pos_connection_id' => $connection->id,
                'article_id' => $article->id,
                'external_product_id' => $product['product_id'],
                'external_variant_id' => $product['variant_id'],
            ]);
            $linkedArticles[] = $article->id;
            $linkedProducts[] = $product['product_id'];
            $linked++;
        }

        return $linked;
    }
}
