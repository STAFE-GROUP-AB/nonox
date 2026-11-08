<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Structures\StructureWriter;
use App\Modules\Kitchen\Actions\Concerns\GuardsKitchen;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Saves a recipe as a new version (ADR-073 §3): a dish (an article sold
 * from the menu, yield in portions) or a sub-recipe (a stocked article such
 * as a sauce, yield in its unit). The structure engine in Lager validates
 * and stores it; earlier versions stay for earlier dates. Also switches a
 * recipe off or on again.
 */
class SaveRecipe
{
    use GuardsKitchen;

    public function __construct(private readonly StructureWriter $writer) {}

    /** @param  array<string, mixed>  $input  as StructureWriter::save */
    public function __invoke(User $user, Company $company, Article $output, array $input): BillOfMaterials
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, fn (): BillOfMaterials => DB::transaction(function () use ($user, $company, $output, $input): BillOfMaterials {
            $output = Article::query()->findOrFail($output->id);
            $recipe = $this->writer->save($company, $user, StructureKind::Recipe, $output, $input);

            Audit::record('kitchen.recipe_saved', $recipe, [
                'article_id' => $output->id,
                'version' => $recipe->version,
                'valid_from' => $recipe->valid_from->toDateString(),
                'rows' => $recipe->rows->count(),
            ], $company, $user);

            return $recipe;
        }));
    }

    public function setActive(User $user, Company $company, Article $output, bool $active): int
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, function () use ($user, $company, $output, $active): int {
            $output = Article::query()->findOrFail($output->id);
            $count = $this->writer->setActive($company, $output, $active);

            Audit::record($active ? 'kitchen.recipe_activated' : 'kitchen.recipe_deactivated', $output, ['article_id' => $output->id, 'versions' => $count], $company, $user);

            return $count;
        });
    }
}
