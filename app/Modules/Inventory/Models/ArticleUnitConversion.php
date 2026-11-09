<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A unit an article may be entered in, besides its own (rule sheet RS-C1):
 * factor_milli is how many thousandths of that unit make one of the
 * article's unit. A 70 cl bottle (unit fl) has cl with 70 000.
 *
 * @property int $id
 * @property int $company_id
 * @property int $article_id
 * @property string $unit
 * @property int $factor_milli
 */
class ArticleUnitConversion extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'article_id', 'unit', 'factor_milli'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['factor_milli' => 'integer'];
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
