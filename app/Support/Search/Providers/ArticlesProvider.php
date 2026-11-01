<?php

declare(strict_types=1);

namespace App\Support\Search\Providers;

use App\Models\Article;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;

/**
 * Artiklar: by article number and name.
 */
final class ArticlesProvider extends DatabaseSearchProvider
{
    public const string KEY = 'articles';

    private const array COLUMNS = ['articles.name', 'articles.number'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Artiklar');
    }

    public function order(): int
    {
        return 22;
    }

    public function authorize(SearchContext $context): bool
    {
        return $context->allows('viewAny', Article::class);
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        if (! $this->wants($query)) {
            return null;
        }

        $builder = Article::query()->where('articles.company_id', $context->company->id)->select('articles.*');
        $identifiers = trim($query->text) === '' ? [] : [['lower(articles.number) = lower(?)', [trim($query->text)]]];

        $this->whereText($builder, self::COLUMNS, $query);
        $this->selectRank($builder, self::COLUMNS, $query, $identifiers);

        [$articles, $total] = $this->fetch($builder, 'articles.updated_at', $limit);

        return $this->group(
            $articles->map(fn (Article $article): SearchResult => $this->result($article)->withScore(self::rank($article)))->all(),
            $total,
            route('invoicing.articles'),
        );
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        $article = ctype_digit($id) ? Article::query()->find((int) $id) : null;

        return $article instanceof Article ? $this->result($article) : null;
    }

    private function result(Article $article): SearchResult
    {
        return new SearchResult(
            type: self::KEY,
            id: (string) $article->id,
            title: $article->name,
            url: route('invoicing.articles', ['redigera' => $article->id]),
            subtitle: __('Artikel :number', ['number' => $article->number]).' · '.__('per :unit', ['unit' => $article->unit]),
            meta: $article->price_ore->format(),
            badge: self::when(! $article->is_active, __('Inaktiv')),
            icon: 'article',
        );
    }
}
