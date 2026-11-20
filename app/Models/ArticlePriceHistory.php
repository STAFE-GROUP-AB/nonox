<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\PriceChangeSource;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One change of an article's base price (articles.price_ore), so "what did
 * this cost on 3 March?" is answerable for the base price too (ADR-082 §1).
 * Written by SaveArticle; ids, amounts and an enum only. Append-only: the
 * model refuses changes, and so does a database trigger.
 *
 * @property int $id
 * @property int $company_id
 * @property int $article_id
 * @property ?int $old_price_ore
 * @property int $new_price_ore
 * @property CarbonImmutable $valid_from
 * @property PriceChangeSource $source
 * @property ?int $price_update_run_id
 * @property ?int $changed_by
 * @property ?CarbonImmutable $created_at
 */
class ArticlePriceHistory extends Model
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    protected $table = 'article_price_history';

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'article_id', 'old_price_ore', 'new_price_ore', 'valid_from', 'source', 'price_update_run_id', 'changed_by',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('The base price history cannot be changed.'));
        static::deleting(fn (): never => throw new LogicException('The base price history cannot be deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_price_ore' => 'integer',
            'new_price_ore' => 'integer',
            'valid_from' => 'immutable_date',
            'source' => PriceChangeSource::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
