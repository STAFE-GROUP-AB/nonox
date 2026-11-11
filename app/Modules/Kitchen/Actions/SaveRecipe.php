<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Structures\StructureWriter;
use App\Modules\Kitchen\Actions\Concerns\GuardsKitchen;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    /**
     * Rows of the last save whose conversion to the stock unit changed the
     * typed quantity by more than 0,5 % (ADR-071 §12.4). The recipe is saved;
     * the screen shows these.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->writer->warnings;
    }

    /** @param  array<string, mixed>  $input  as StructureWriter::save */
    public function __invoke(User $user, Company $company, Article $output, array $input): BillOfMaterials
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, fn (): BillOfMaterials => DB::transaction(function () use ($user, $company, $output, $input): BillOfMaterials {
            $output = Article::query()->findOrFail($output->id);
            $backdated = $this->backdating($input);
            $recipe = $this->writer->save($company, $user, StructureKind::Recipe, $output, $input);

            if ($backdated !== null) {
                Audit::record('kitchen.recipe_backdated', $recipe, ['article_id' => $output->id, 'version' => $recipe->version, 'valid_from' => $recipe->valid_from->toDateString(), 'latest_count' => $backdated], $company, $user);
            }

            Audit::record('kitchen.recipe_saved', $recipe, [
                'article_id' => $output->id,
                'version' => $recipe->version,
                'valid_from' => $recipe->valid_from->toDateString(),
                'rows' => $recipe->rows->count(),
            ], $company, $user);

            return $recipe;
        }));
    }

    /**
     * A recipe dated back changes figures already shown (theoretical
     * consumption, variances of counted periods), so a date before today,
     * or before the latest completed stock count, needs an explicit
     * confirmation (Lead decision on review P2.7). Returns the latest count
     * date when the recipe is backdated and confirmed, null when it is not
     * backdated.
     *
     * @param  array<string, mixed>  $input
     */
    private function backdating(array $input): ?string
    {
        try {
            $validFrom = CarbonImmutable::parse((string) (($input['valid_from'] ?? null) ?: CarbonImmutable::today()->toDateString()))->startOfDay();
        } catch (Throwable) {
            return null;
        }

        $latestCount = StockCount::query()->where('status', StockCount::COMPLETED)->max('counted_on');
        $beforeCount = $latestCount !== null && $validFrom->lessThan(CarbonImmutable::parse((string) $latestCount));

        if (! $validFrom->lessThan(CarbonImmutable::today()) && ! $beforeCount) {
            return null;
        }

        if (! (bool) ($input['confirm_backdate'] ?? false)) {
            throw ValidationException::withMessages(['valid_from' => $beforeCount
                ? __('Receptet skulle gälla före inventeringen :date och ändra redan räknade perioder. Bekräfta att det ska gälla bakåt i tiden.', ['date' => substr((string) $latestCount, 0, 10)])
                : __('Receptet skulle gälla bakåt i tiden och ändra redan visade siffror. Bekräfta att det ska gälla från :date.', ['date' => $validFrom->toDateString()])]);
        }

        return $latestCount === null ? $validFrom->toDateString() : substr((string) $latestCount, 0, 10);
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
