<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Support;

use App\Models\Article;
use App\Modules\Inventory\Structures\StructureBook;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * What a quantity of a dish or sub-recipe takes from stock (ADR-073 §6,
 * §7): exploded through the recipe valid on the date to the ingredients,
 * rounded half up once per ingredient (RS-C7), keeping only stocked
 * articles. Ingredients that are not stocked (salt, water) are returned
 * apart, so the caller can say so.
 */
final class RecipeStock
{
    /**
     * @return array{lines: array<int, int>, skipped: list<int>} stocked ingredient => thousandths out, and the others
     *
     * @throws ValidationException when the article has no recipe on the date
     */
    public static function lines(StructureBook $book, Article $article, int $quantityMilli, CarbonImmutable $date, string $field = 'article_id'): array
    {
        if ($book->validOn($article->id, $date) === null) {
            throw ValidationException::withMessages([$field => __(':name har inget recept som gäller :date.', ['name' => $article->name, 'date' => $date->toDateString()])]);
        }

        $components = $book->explodeRounded([$article->id => $quantityMilli], $date)['components'];
        $stocked = Article::query()->whereKey(array_keys($components))->where('is_stock_item', true)->pluck('id')->all();
        $lines = [];
        $skipped = [];

        foreach ($components as $articleId => $milli) {
            if (! in_array($articleId, $stocked, true)) {
                $skipped[] = $articleId;

                continue;
            }

            if ($milli !== 0) {
                $lines[$articleId] = $milli;
            }
        }

        return ['lines' => $lines, 'skipped' => $skipped];
    }
}
