<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Structures\StructureWriter;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Models\Operation;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Saves a bill of materials as a new version (ADR-071 §3): components on
 * several levels (a component may have its own bill of materials; a loop
 * is refused) with quantity, unit and scrap, and operations with their
 * minutes for the batch. The structure engine in Lager validates and
 * stores it (ADR-073). A change to an active bill of materials is a new
 * version; quotes and orders keep the copy they made, and the version it
 * came from. Also switches a bill of materials off or on again.
 */
class SaveBillOfMaterials
{
    use GuardsManufacturing;

    public function __construct(private readonly StructureWriter $writer) {}

    /**
     * Rows of the last save whose conversion to the stock unit changed the
     * typed quantity by more than 0,5 % (ADR-071 §12.4). The bill of
     * materials is saved; the screen shows these.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->writer->warnings;
    }

    /** @param  array<string, mixed>  $input  as StructureWriter::save, plus confirm_backdate */
    public function __invoke(User $user, Company $company, Article $output, array $input): BillOfMaterials
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, fn (): BillOfMaterials => DB::transaction(function () use ($user, $company, $output, $input): BillOfMaterials {
            $output = Article::query()->findOrFail($output->id);
            $backdated = $this->backdating($input);
            $operations = Operation::query()->active()->pluck('name', 'id')->all();
            $structure = $this->writer->save($company, $user, StructureKind::Bom, $output, $input, $operations);

            if ($backdated !== null) {
                Audit::record('manufacturing.bom_backdated', $structure, ['article_id' => $output->id, 'version' => $structure->version, 'valid_from' => $structure->valid_from->toDateString(), 'latest_count' => $backdated], $company, $user);
            }

            Audit::record('manufacturing.bom_saved', $structure, [
                'article_id' => $output->id,
                'version' => $structure->version,
                'valid_from' => $structure->valid_from->toDateString(),
                'components' => $structure->rows->whereNotNull('component_article_id')->count(),
                'operations' => $structure->rows->whereNotNull('operation_id')->count(),
            ], $company, $user);

            return $structure;
        }));
    }

    public function setActive(User $user, Company $company, Article $output, bool $active): int
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, function () use ($user, $company, $output, $active): int {
            $output = Article::query()->findOrFail($output->id);
            $count = $this->writer->setActive($company, $output, $active);

            Audit::record($active ? 'manufacturing.bom_activated' : 'manufacturing.bom_deactivated', $output, ['article_id' => $output->id, 'versions' => $count], $company, $user);

            return $count;
        });
    }

    /**
     * The backdating guard of the note of 2026-11-09 in ADR-071: a version
     * dated before today, or before the latest completed stock count,
     * changes what applied on days already passed, so it needs an explicit
     * confirmation and is audited. Production orders, which will keep the
     * version they were made from, do not exist yet. Returns the latest
     * count date (or the date) when backdated and confirmed, null when not.
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
                ? __('Stycklistan skulle gälla före inventeringen :date. Bekräfta att den ska gälla bakåt i tiden.', ['date' => substr((string) $latestCount, 0, 10)])
                : __('Stycklistan skulle gälla bakåt i tiden. Bekräfta att den ska gälla från :date.', ['date' => $validFrom->toDateString()])]);
        }

        return $latestCount === null ? $validFrom->toDateString() : substr((string) $latestCount, 0, 10);
    }
}
