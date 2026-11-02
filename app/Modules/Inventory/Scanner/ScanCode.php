<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Scanner;

use App\Models\Article;
use App\Modules\Inventory\Models\StockLocation;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a scanned or typed code means (ADR-040). The same rules run on the
 * phone (scanner-core.js, resolveCode) for offline lookups:
 *
 * - "L:" + full_code is a location label, "A:" + number an article label.
 * - Otherwise an article is looked for by GTIN (a 13-digit code with a
 *   leading zero also matches the 12-digit GTIN, and the other way round),
 *   then scanner code, SKU and number; then a location by its full code.
 *
 * Prefixes and location codes are read without regard to case.
 */
final class ScanCode
{
    public const string LOCATION_PREFIX = 'L:';

    public const string ARTICLE_PREFIX = 'A:';

    /**
     * @return array{type: 'article', article: Article}|array{type: 'location', location: StockLocation}|array{type: null}
     */
    public static function resolve(string $raw): array
    {
        $code = trim($raw);

        if ($code === '' || mb_strlen($code) > 120) {
            return ['type' => null];
        }

        $prefix = mb_strtoupper(mb_substr($code, 0, 2));

        if ($prefix === self::LOCATION_PREFIX) {
            $location = self::location(mb_substr($code, 2));

            return $location === null ? ['type' => null] : ['type' => 'location', 'location' => $location];
        }

        if ($prefix === self::ARTICLE_PREFIX) {
            $article = self::stocked()->where('number', trim(mb_substr($code, 2)))->first();

            return $article === null ? ['type' => null] : ['type' => 'article', 'article' => $article];
        }

        $article = self::article($code);

        if ($article !== null) {
            return ['type' => 'article', 'article' => $article];
        }

        $location = self::location($code);

        return $location === null ? ['type' => null] : ['type' => 'location', 'location' => $location];
    }

    /**
     * The GTINs a digit code may stand for: itself, and the same number with
     * or without one leading zero (UPC-A printed as EAN-13, GTIN-14).
     *
     * @return list<string>
     */
    public static function gtinCandidates(string $code): array
    {
        if (preg_match('/^\d{8,14}$/', $code) !== 1) {
            return [];
        }

        $candidates = [$code];

        if (str_starts_with($code, '0') && strlen($code) > 8) {
            $candidates[] = substr($code, 1);
        }

        if (strlen($code) < 14) {
            $candidates[] = '0'.$code;
        }

        return array_values(array_unique($candidates));
    }

    private static function article(string $code): ?Article
    {
        $gtins = self::gtinCandidates($code);

        if ($gtins !== []) {
            $article = self::stocked()->whereIn('gtin', $gtins)->first();

            if ($article !== null) {
                return $article;
            }
        }

        foreach (['scanner_code', 'sku', 'number'] as $column) {
            $article = self::stocked()->where($column, $code)->first();

            if ($article !== null) {
                return $article;
            }
        }

        return null;
    }

    private static function location(string $code): ?StockLocation
    {
        $code = mb_strtoupper(trim($code));

        return $code === '' ? null : StockLocation::query()->whereRaw('upper(full_code) = ?', [$code])->first();
    }

    /** @return Builder<Article> */
    private static function stocked(): Builder
    {
        return Article::query()->where('is_stock_item', true);
    }
}
