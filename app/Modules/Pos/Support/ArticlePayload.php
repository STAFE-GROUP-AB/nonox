<?php

declare(strict_types=1);

namespace App\Modules\Pos\Support;

use App\Models\Article;

/**
 * What a till needs to know about an article, in NONOX's terms: the
 * article's price is excluding VAT (AGENTS.md §7), tills show prices
 * including VAT, so the gross price is the net price plus VAT at the
 * article's rate, rounded half up to öre.
 */
final class ArticlePayload
{
    public static function grossOre(Article $article): int
    {
        return $article->price_ore->plus($article->price_ore->percent($article->vat_rate))->ore;
    }

    /** The scanned code the till should know: GTIN, else the scanner code. */
    public static function barcode(Article $article): ?string
    {
        foreach ([$article->gtin, $article->scanner_code] as $code) {
            if (is_string($code) && trim($code) !== '') {
                return trim($code);
            }
        }

        return null;
    }

    /** A hash of what is sent, so an unchanged article is not sent again. */
    public static function hash(Article $article): string
    {
        return hash('sha256', (string) json_encode([
            mb_substr(trim($article->name), 0, 255),
            self::grossOre($article),
            $article->vat_rate,
            self::barcode($article),
            $article->sku,
            $article->number,
            $article->description,
            $article->is_active,
        ]));
    }
}
