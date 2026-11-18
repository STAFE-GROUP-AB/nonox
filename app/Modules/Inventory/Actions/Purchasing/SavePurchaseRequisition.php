<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Domain\Units\UnitRegister;
use App\Models\Account;
use App\Models\Article;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\NumberSeries;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Inventory\Domain\PurchaseRequisitionStatus;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Modules\Inventory\Models\PurchaseRequisitionRow;
use App\Modules\Inventory\Purchasing\PurchaseQuantities;
use App\Modules\Inventory\Purchasing\PurchaseRequisitionApprovals;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or changes a purchase requisition while the requester may still
 * change it (ADR-081 gap 3, E9): a draft, or one that was rejected. A row asks
 * for an article, or for something in free text, in a quantity and unit with
 * an estimated price, and may name an account, cost centre, project and
 * supplier. Saving again starts a new approval round and takes a rejected
 * requisition back to a draft, so an approval never covers something other
 * than what the approver saw. Nothing is ordered and nothing is booked.
 */
class SavePurchaseRequisition
{
    public const int MAX_ROWS = 100;

    /**
     * @param  array<string, mixed>  $input  needed_by, reason, rows: list<{article_id?, description?, quantity, unit_id?, price?, account?, cost_center?, project?, supplier_id?}>
     */
    public function __invoke(User $user, Company $company, array $input, ?PurchaseRequisition $requisition = null): PurchaseRequisition
    {
        app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);

        if ($requisition === null) {
            Gate::forUser($user)->authorize('create', [PurchaseRequisition::class, $company]);
        } else {
            Gate::forUser($user)->authorize('handle', [$requisition, $company]);
        }

        $validated = Validator::make($input, [
            'needed_by' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*.article_id' => ['nullable', 'integer'],
            'rows.*.description' => ['nullable', 'string', 'max:255'],
            'rows.*.quantity' => ['required'],
            'rows.*.unit_id' => ['nullable', 'integer'],
            'rows.*.price' => ['nullable'],
            'rows.*.account' => ['nullable', 'string', 'max:10'],
            'rows.*.cost_center' => ['nullable', 'string', 'max:50'],
            'rows.*.project' => ['nullable', 'string', 'max:50'],
            'rows.*.supplier_id' => ['nullable', 'integer'],
        ], [
            'needed_by.date_format' => __('Skriv datumet som ÅÅÅÅ-MM-DD.'),
            'reason.max' => __('Skälet får vara högst :max tecken.', ['max' => 500]),
            'rows.required' => __('Lägg till minst en rad.'),
            'rows.min' => __('Lägg till minst en rad.'),
            'rows.max' => __('En inköpsanmodan kan ha högst :max rader.', ['max' => self::MAX_ROWS]),
        ])->validate();

        return CurrentCompany::run($company, fn (): PurchaseRequisition => DB::transaction(function () use ($validated, $requisition, $company, $user): PurchaseRequisition {
            if ($requisition !== null) {
                $requisition = PurchaseRequisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

                // The lock may have waited for someone who withdrew or sent it: the policy is checked again on the locked row.
                if (! Gate::forUser($user)->allows('handle', [$requisition, $company])) {
                    throw new AuthorizationException;
                }

                if (! $requisition->status->isEditable()) {
                    throw ValidationException::withMessages(['requisition' => __('Inköpsanmodan väntar på attest eller är redan avgjord. Ta tillbaka den först om du vill ändra den.')]);
                }

                // What was approved or rejected is not what is saved now.
                PurchaseRequisitionApprovals::startNewRound($requisition, $user);
            }

            $rows = $this->rows(array_values((array) $validated['rows']));

            $attributes = [
                'needed_by' => ($validated['needed_by'] ?? null) ?: null,
                'reason' => self::text($validated['reason'] ?? null),
                'prepared_by' => $user->id,
            ];

            if ($requisition === null) {
                $requisition = PurchaseRequisition::create([
                    ...$attributes,
                    'number' => NumberSeries::take(PurchaseRequisition::SERIES),
                    'status' => PurchaseRequisitionStatus::Draft,
                    'requested_by' => $user->id,
                ]);
            } else {
                $requisition->update([...$attributes, 'status' => PurchaseRequisitionStatus::Draft, 'submitted_at' => null]);
                PurchaseRequisitionRow::query()->where('purchase_requisition_id', $requisition->id)->delete();
            }

            foreach ($rows as $position => $row) {
                PurchaseRequisitionRow::create([...$row, 'purchase_requisition_id' => $requisition->id, 'position' => $position]);
            }

            Audit::record('inventory.purchase_requisition_saved', $requisition, ['number' => $requisition->number, 'rows' => count($rows), 'round' => $requisition->approval_round], $company, $user);

            return $requisition->load('rows');
        }));
    }

    /**
     * @param  list<mixed>  $input
     * @return list<array<string, mixed>>
     */
    private function rows(array $input): array
    {
        $articles = Article::query()->with(['stockUnit', 'purchaseUnit'])
            ->whereKey(array_filter(array_map(fn (mixed $row): int => (int) (is_array($row) ? ($row['article_id'] ?? 0) : 0), $input)))
            ->get()->keyBy('id');
        $conversions = PurchaseQuantities::conversions($articles->keys()->all());
        $register = UnitRegister::load();
        $units = $register->all()->keyBy('id');
        $lookups = $this->lookups($input);
        $rows = [];

        foreach ($input as $index => $row) {
            $article = null;

            if ((int) ($row['article_id'] ?? 0) !== 0) {
                $article = $articles->get((int) $row['article_id']);

                if ($article === null || ! $article->is_active) {
                    throw ValidationException::withMessages(["rows.{$index}.article_id" => __('Artikeln finns inte eller är avstängd.')]);
                }
            }

            $description = self::text($row['description'] ?? null) ?? ($article === null ? null : mb_substr($article->name, 0, 255));

            if ($description === null) {
                throw ValidationException::withMessages(["rows.{$index}.description" => __('Beskriv vad du behöver, eller välj en artikel.')]);
            }

            $fallbackUnit = $article === null ? $register->default()->id : (int) ($article->purchase_unit_id ?? $article->stock_unit_id ?? $register->default()->id);
            $unit = $units->get((int) (($row['unit_id'] ?? null) ?: $fallbackUnit)) ?? throw ValidationException::withMessages(["rows.{$index}.unit_id" => __('Enheten finns inte.')]);

            if ($unit->isArchived()) {
                throw ValidationException::withMessages(["rows.{$index}.unit_id" => __('Enheten :unit är arkiverad. Välj en annan enhet.', ['unit' => $unit->code])]);
            }

            if ($article !== null && $article->is_stock_item && PurchaseQuantities::perStock($article, $unit, $conversions[$article->id] ?? []) === null) {
                throw ValidationException::withMessages(["rows.{$index}.unit_id" => __(':name går inte att köpa i :unit. Välj en enhet artikeln kan räknas om till.', ['name' => $article->name, 'unit' => $unit->code])]);
            }

            try {
                $quantity = Quantity::parse((string) ($row['quantity'] ?? ''));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["rows.{$index}.quantity" => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
            }

            if ($quantity <= 0) {
                throw ValidationException::withMessages(["rows.{$index}.quantity" => __('Antalet måste vara större än noll.')]);
            }

            $price = null;

            if (($row['price'] ?? null) !== null && trim((string) $row['price']) !== '') {
                try {
                    $price = Money::parse((string) $row['price'])->ore;
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(["rows.{$index}.price" => __('Beloppet går inte att läsa. Skriv till exempel 149,00.')]);
                }

                if ($price < 0) {
                    throw ValidationException::withMessages(["rows.{$index}.price" => __('Beloppet kan inte vara negativt.')]);
                }
            }

            [$account, $costCenter, $project] = $this->dimensions($index, $row, $lookups);

            // Something that is not kept in stock is ordered on an account, so the requester must name it (a stock article needs none).
            if (($article === null || ! $article->is_stock_item) && $account === null) {
                throw ValidationException::withMessages(["rows.{$index}.account" => __('Ange vilket konto kostnaden hör till. Det behövs för det som inte är en lagerartikel.')]);
            }

            $supplierId = (int) ($row['supplier_id'] ?? 0);
            $supplier = $supplierId === 0 ? null : ($lookups['suppliers']->get($supplierId) ?? throw ValidationException::withMessages(["rows.{$index}.supplier_id" => __('Leverantören finns inte.')]));
            $supplier?->ensureNotRestricted("rows.{$index}.supplier_id");

            $rows[] = [
                'article_id' => $article?->id,
                'description' => mb_substr($description, 0, 255),
                'quantity_milli' => $quantity,
                'unit_id' => $unit->id,
                'estimated_price_ore' => $price,
                'account_id' => $account?->id,
                'cost_center_id' => $costCenter?->id,
                'project_id' => $project?->id,
                'supplier_id' => $supplier?->id,
            ];
        }

        return $rows;
    }

    /**
     * The accounts, cost centres, projects and suppliers the rows name, looked
     * up once for all rows.
     *
     * @param  list<mixed>  $input
     * @return array{accounts: Collection<int, Account>, costCenters: Collection<string, CostCenter>, projects: Collection<string, Project>, suppliers: Collection<int, Supplier>}
     */
    private function lookups(array $input): array
    {
        $column = fn (string $key): array => array_values(array_filter(array_map(fn (mixed $row): string => trim((string) (is_array($row) ? ($row[$key] ?? '') : '')), $input), fn (string $value): bool => $value !== ''));

        return [
            'accounts' => Account::query()->whereIn('number', array_map('intval', $column('account')))->get()->keyBy('number'),
            'costCenters' => CostCenter::query()->whereIn('code', $column('cost_center'))->get()->keyBy('code'),
            'projects' => Project::query()->whereIn('code', $column('project'))->get()->keyBy('code'),
            'suppliers' => Supplier::query()->whereKey(array_map('intval', $column('supplier_id')))->get()->keyBy('id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{accounts: Collection<int, Account>, costCenters: Collection<string, CostCenter>, projects: Collection<string, Project>, suppliers: Collection<int, Supplier>}  $lookups
     * @return array{0: ?Account, 1: ?CostCenter, 2: ?Project}
     */
    private function dimensions(int $index, array $row, array $lookups): array
    {
        $number = (int) ($row['account'] ?? 0);
        $account = $number === 0 ? null : $lookups['accounts']->get($number);

        if ($number !== 0 && ($account === null || ! $account->is_active)) {
            throw ValidationException::withMessages(["rows.{$index}.account" => __('Kontot :number finns inte i kontoplanen eller är avstängt.', ['number' => $number])]);
        }

        if ($account !== null && ! (($account->number >= 4000 && $account->number <= 7999) || ($account->number >= 1000 && $account->number <= 1299))) {
            throw ValidationException::withMessages(["rows.{$index}.account" => __('Välj ett kostnadskonto (4000–7999) eller ett konto för anläggningstillgångar (1000–1299).')]);
        }

        $costCenterCode = trim((string) ($row['cost_center'] ?? ''));
        $projectCode = trim((string) ($row['project'] ?? ''));
        $costCenter = $costCenterCode === '' ? null : $lookups['costCenters']->get($costCenterCode);
        $project = $projectCode === '' ? null : $lookups['projects']->get($projectCode);

        if ($costCenterCode !== '' && ($costCenter === null || ! $costCenter->is_active)) {
            throw ValidationException::withMessages(["rows.{$index}.cost_center" => __('Kostnadsstället :code finns inte eller är avstängt.', ['code' => $costCenterCode])]);
        }

        if ($projectCode !== '' && ($project === null || ! $project->is_active)) {
            throw ValidationException::withMessages(["rows.{$index}.project" => __('Projektet :code finns inte eller är avstängt.', ['code' => $projectCode])]);
        }

        if ($account !== null && $account->requires_cost_center && $costCenter === null) {
            throw ValidationException::withMessages(["rows.{$index}.cost_center" => __('Konto :number kräver ett kostnadsställe.', ['number' => $account->number])]);
        }

        if ($account !== null && $account->requires_project && $project === null) {
            throw ValidationException::withMessages(["rows.{$index}.project" => __('Konto :number kräver ett projekt.', ['number' => $account->number])]);
        }

        return [$account, $costCenter, $project];
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
