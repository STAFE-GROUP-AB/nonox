<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Scanner;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Scanner\ScannerExtension;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Inventory\Support\LocationTree;
use App\Modules\Kitchen\Actions\RegisterWaste;
use App\Modules\Kitchen\Domain\WasteReason;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\Kitchen\KitchenSettings;
use App\Modules\Kitchen\Models\WasteEntry;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * The scanner's Svinn tile (ADR-073 §6): the dishes and sub-recipes with a
 * recipe today, the reason codes and the kitchen's location for the phone,
 * never a price or a cost; and the operation "waste", run through
 * RegisterWaste exactly once per UUID by the scanner.
 *
 * Payload of "waste": kind (INGREDIENT or DISH), article_id, quantity as
 * decimal text, location_id (optional), reason, recipient, note.
 */
final class KitchenScannerExtension implements ScannerExtension
{
    public function key(): string
    {
        return 'waste';
    }

    public function kinds(): array
    {
        return ['waste'];
    }

    public function allows(User $user, Company $company): bool
    {
        return app(ModuleManager::class)->isEnabled(KitchenModule::KEY, $company)
            && Gate::forUser($user)->allows('register', [WasteEntry::class, $company]);
    }

    /** The kitchen's location and everything under it; every location while none is chosen. */
    public function locationIds(User $user, Company $company): ?array
    {
        $id = KitchenSettings::locationId($company);
        $location = $id === null ? null : CurrentCompany::run($company, fn (): ?StockLocation => StockLocation::query()->find($id));

        return $location === null ? null : array_map(intval(...), LocationTree::subtreeIds($location));
    }

    public function data(User $user, Company $company): array
    {
        $book = StructureBook::for($company);
        $today = CarbonImmutable::today();
        $made = array_values(array_filter($book->outputs(), fn (int $id): bool => $book->validOn($id, $today) !== null));
        $articles = Article::query()->whereKey($made)->orderBy('name')->get(['id', 'number', 'name', 'unit']);

        return [
            'location_id' => KitchenSettings::locationId($company),
            'reasons' => WasteReason::options(),
            'dishes' => $articles->map(function (Article $article) use ($book, $today): array {
                $recipe = $book->validOn($article->id, $today);

                return [
                    'id' => $article->id,
                    'number' => $article->number,
                    'name' => $article->name,
                    'unit' => $recipe instanceof BillOfMaterials && $recipe->isPortions() ? __('port.') : $article->unit,
                ];
            })->values()->all(),
        ];
    }

    public function run(User $user, Company $company, string $kind, array $payload): array
    {
        $entry = app(RegisterWaste::class)($user, $company, [
            'kind' => is_string($payload['kind'] ?? null) ? $payload['kind'] : '',
            'article_id' => is_numeric($payload['article_id'] ?? null) ? (int) $payload['article_id'] : null,
            'quantity' => is_scalar($payload['quantity'] ?? null) ? (string) $payload['quantity'] : '',
            'location_id' => is_numeric($payload['location_id'] ?? null) ? (int) $payload['location_id'] : null,
            'reason' => is_string($payload['reason'] ?? null) ? $payload['reason'] : '',
            'recipient' => is_string($payload['recipient'] ?? null) ? $payload['recipient'] : null,
            'note' => is_string($payload['note'] ?? null) ? $payload['note'] : null,
        ]);

        return ['waste_entry_id' => $entry->id, 'quantity' => Quantity::decimal($entry->quantity_milli)];
    }
}
