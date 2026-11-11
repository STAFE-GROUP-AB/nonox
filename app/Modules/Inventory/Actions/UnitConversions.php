<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Domain\Units\ArticleUnits;
use App\Domain\Units\UnitConverter;
use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\StructureUnits;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Validation\ValidationException;

/**
 * The units an article may be entered in on a recipe row besides its own
 * (rule sheet RS-C1): "1 fl = 70 cl" is saved as cl with 70 000. Changing
 * a conversion never changes a saved recipe; rows are stored in the
 * article's stock unit (ADR-071 §12.2); the conversion is an exact fraction. Needs inventory:manage.
 */
class UnitConversions
{
    use GuardsInventory;

    public function save(User $user, Company $company, Article $article, string $unit, string $perStockUnit): ArticleUnitConversion
    {
        $this->guard($user, $company, 'manage');
        $unit = StructureUnits::normalise($unit);

        if ($unit === '' || mb_strlen($unit) > 10) {
            throw ValidationException::withMessages(['unit' => __('Skriv enheten, till exempel cl eller st.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $article, $unit, $perStockUnit): ArticleUnitConversion {
            $article = Article::query()->with('stockUnit')->findOrFail($article->id);
            $stock = ArticleUnits::stockCode($article);

            if (UnitConverter::enteredPerStock($stock, $unit) !== null) {
                throw ValidationException::withMessages(['unit' => __(':unit räknas redan om utan omräkning.', ['unit' => $unit])]);
            }

            $fraction = ArticleUnits::parseFraction($perStockUnit);

            if ($fraction === null) {
                throw ValidationException::withMessages(['factor' => __('Skriv hur många :unit en :article är, till exempel 70. Mer än noll, högst nio decimaler.', ['unit' => $unit, 'article' => $stock])]);
            }

            $conversion = ArticleUnits::saveManual($article, $unit, $fraction);
            Audit::record('inventory.unit_conversion_saved', $article, ['unit' => $unit, 'numerator' => $conversion->numerator, 'denominator' => $conversion->denominator], $company, $user);

            return $conversion;
        });
    }

    public function delete(User $user, Company $company, int $conversionId): void
    {
        $this->guard($user, $company, 'manage');

        CurrentCompany::run($company, function () use ($user, $company, $conversionId): void {
            $conversion = ArticleUnitConversion::query()->findOrFail($conversionId);
            $conversion->delete();
            ArticleUnits::syncDerived(Article::query()->with('stockUnit')->findOrFail($conversion->article_id));
            Audit::record('inventory.unit_conversion_deleted', null, ['article_id' => $conversion->article_id, 'unit' => $conversion->unit], $company, $user);
        });
    }
}
