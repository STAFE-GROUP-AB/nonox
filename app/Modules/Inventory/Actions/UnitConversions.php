<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureUnits;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The units an article may be entered in on a recipe row besides its own
 * (rule sheet RS-C1): "1 fl = 70 cl" is saved as cl with 70 000. Changing
 * a conversion never changes a saved recipe; rows are stored in the
 * article's own unit. Needs inventory:manage.
 */
class UnitConversions
{
    use GuardsInventory;

    public function save(User $user, Company $company, Article $article, string $unit, string $perArticleUnit): ArticleUnitConversion
    {
        $this->guard($user, $company, 'manage');
        $unit = StructureUnits::normalise($unit);

        if ($unit === '' || mb_strlen($unit) > 10) {
            throw ValidationException::withMessages(['unit' => __('Skriv enheten, till exempel cl eller st.')]);
        }

        if ($unit === StructureUnits::normalise($article->unit) || isset(StructureUnits::FIXED[StructureUnits::normalise($article->unit)][$unit])) {
            throw ValidationException::withMessages(['unit' => __(':unit räknas redan om utan omräkning.', ['unit' => $unit])]);
        }

        try {
            $factor = Quantity::parse($perArticleUnit);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['factor' => __('Skriv hur många :unit en :article är, till exempel 70.', ['unit' => $unit, 'article' => $article->unit])]);
        }

        if ($factor <= 0) {
            throw ValidationException::withMessages(['factor' => __('Antalet måste vara mer än noll.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $article, $unit, $factor): ArticleUnitConversion {
            $article = Article::query()->findOrFail($article->id);
            $conversion = ArticleUnitConversion::query()->updateOrCreate(['article_id' => $article->id, 'unit' => $unit], ['factor_milli' => $factor]);
            Audit::record('inventory.unit_conversion_saved', $article, ['unit' => $unit, 'factor_milli' => $factor], $company, $user);

            return $conversion;
        });
    }

    public function delete(User $user, Company $company, int $conversionId): void
    {
        $this->guard($user, $company, 'manage');

        CurrentCompany::run($company, function () use ($user, $company, $conversionId): void {
            $conversion = ArticleUnitConversion::query()->findOrFail($conversionId);
            $conversion->delete();
            Audit::record('inventory.unit_conversion_deleted', null, ['article_id' => $conversion->article_id, 'unit' => $conversion->unit], $company, $user);
        });
    }
}
