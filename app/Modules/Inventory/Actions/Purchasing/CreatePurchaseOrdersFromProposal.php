<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsPurchaseOrders;
use App\Modules\Inventory\Domain\PurchaseOrderSource;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Skapa inköpsordrar för det som saknas" (ADR-071 §5): the chosen proposal
 * lines become one draft purchase order per supplier. An article without a
 * supplier needs one chosen first. Nothing is sent; each order is sent on
 * its own after the user has seen it (§2).
 */
class CreatePurchaseOrdersFromProposal
{
    use GuardsPurchaseOrders;

    public function __construct(private readonly SavePurchaseOrder $save) {}

    /**
     * @param  list<array{article_id: mixed, quantity: mixed, unit_id?: mixed, supplier_id?: mixed}>  $lines
     * @return list<PurchaseOrder>
     */
    public function __invoke(User $user, Company $company, array $lines): array
    {
        $this->guard($user, $company, 'manage');

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Välj minst en artikel att beställa.')]);
        }

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $lines): array {
            $articles = Article::query()->whereKey(array_map(fn (array $line): int => (int) $line['article_id'], $lines))->get(['id', 'number', 'name', 'default_supplier_id'])->keyBy('id');
            $perSupplier = [];

            foreach ($lines as $index => $line) {
                $article = $articles->get((int) $line['article_id']) ?? throw ValidationException::withMessages(["lines.{$index}.article_id" => __('Artikeln finns inte.')]);
                $supplierId = (int) (($line['supplier_id'] ?? null) ?: $article->default_supplier_id);

                if ($supplierId === 0) {
                    throw ValidationException::withMessages(["lines.{$index}.supplier_id" => __('Välj leverantör för :number :name.', ['number' => $article->number, 'name' => $article->name])]);
                }

                $perSupplier[$supplierId][] = ['article_id' => $article->id, 'quantity' => $line['quantity'], 'unit_id' => $line['unit_id'] ?? null];
            }

            $orders = [];

            foreach ($perSupplier as $supplierId => $rows) {
                $orders[] = ($this->save)($user, $company, ['supplier_id' => $supplierId, 'rows' => $rows], null, PurchaseOrderSource::Proposal);
            }

            return $orders;
        }));
    }
}
