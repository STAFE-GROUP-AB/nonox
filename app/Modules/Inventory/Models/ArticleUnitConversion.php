<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Support\Tenancy\BelongsToCompany;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A unit an article may be entered in besides its stock unit (ADR-071
 * §12.2): one stock unit is numerator/denominator of this unit, stored
 * reduced, so a 70 cl bottle (stock unit fl) has cl = 70/1. `source` is
 * MANUAL (entered) or DIMENSIONS (derived from the material's size, §12.3);
 * a manual row wins. factor_milli is the old thousandths form, kept for
 * rows written before the fraction and filled in when it fits.
 *
 * @property int $id
 * @property int $company_id
 * @property int $article_id
 * @property string $unit
 * @property ?int $factor_milli
 * @property int $numerator
 * @property int $denominator
 * @property string $source
 */
class ArticleUnitConversion extends Model
{
    use BelongsToCompany;

    public const string MANUAL = 'MANUAL';

    public const string DIMENSIONS = 'DIMENSIONS';

    /** @var list<string> */
    protected $fillable = ['company_id', 'article_id', 'unit', 'factor_milli', 'numerator', 'denominator', 'source'];

    protected static function booted(): void
    {
        static::saving(function (ArticleUnitConversion $conversion): void {
            if ($conversion->getAttribute('numerator') === null && $conversion->getAttribute('factor_milli') !== null) {
                $conversion->setFraction(BigRational::of((int) $conversion->factor_milli)->dividedBy(1000));
            }

            if ($conversion->getAttribute('factor_milli') === null && $conversion->getAttribute('numerator') !== null) {
                $milli = $conversion->fraction()->multipliedBy(1000)->toScale(0, RoundingMode::HalfUp)->toInt();
                $conversion->factor_milli = $milli > 0 ? $milli : null;
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['factor_milli' => 'integer', 'numerator' => 'integer', 'denominator' => 'integer'];
    }

    /** How much of this unit one stock unit is, exactly. */
    public function fraction(): BigRational
    {
        return BigRational::of($this->numerator)->dividedBy($this->denominator);
    }

    /** Stores a fraction reduced (BigRational reduces itself). */
    public function setFraction(BigRational $fraction): void
    {
        $this->numerator = $fraction->getNumerator()->toInt();
        $this->denominator = $fraction->getDenominator()->toInt();
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
