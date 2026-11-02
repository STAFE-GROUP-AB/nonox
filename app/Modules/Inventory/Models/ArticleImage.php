<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A picture of an article, in order; position 0 is the main image. Written
 * only through ArticleImages, served through ArticleImageController and the
 * API (ADR-039).
 *
 * @property int $id
 * @property int $company_id
 * @property int $article_id
 * @property string $path
 * @property int $position
 * @property ?string $alt
 */
class ArticleImage extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'article_id',
        'path',
        'position',
        'alt',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
