<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\PurchaseOrderSource;
use App\Modules\Inventory\Domain\PurchaseRequisitionStatus;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Modules\Inventory\Models\PurchaseRequisitionRow;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A buyer turns approved purchase requisition rows into the rows of one draft
 * purchase order to one supplier (ADR-081 E9). Several requisitions can go into
 * the same order; every requisition row keeps the link to the order row it
 * became, and a requisition is converted when all its rows are. Rows that name
 * another supplier are not taken. A row without a stock article needs an
 * account and a price: the buyer fills in what the requester left out.
 *
 * The order is an ordinary draft, so it goes through the order's own approval
 * and sending (E10). Nothing is booked.
 */
class ConvertPurchaseRequisitions
{
    public function __construct(private readonly SavePurchaseOrder $save) {}

    /**
     * @param  array<int, int>  $rowIds  requisition rows, all on approved requisitions
     * @param  array<int, array{account?: ?string, price?: ?string}>  $details  what the buyer fills in per row id
     */
    public function __invoke(User $user, Company $company, array $rowIds, int $supplierId, array $details = []): PurchaseOrder
    {
        app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);
        Gate::forUser($user)->authorize('convert', [PurchaseRequisition::class, $company]);

        $rowIds = array_values(array_unique(array_map('intval', $rowIds)));

        if ($supplierId <= 0) {
            throw ValidationException::withMessages(['convert' => __('Välj leverantör.')]);
        }

        if ($rowIds === []) {
            throw ValidationException::withMessages(['convert' => __('Välj minst en rad att beställa.')]);
        }

        return CurrentCompany::run($company, fn (): PurchaseOrder => DB::transaction(function () use ($user, $company, $rowIds, $supplierId, $details): PurchaseOrder {
            // Requisitions first (in id order), then their rows, so two buyers never wait on each other.
            $rowRequisitionIds = PurchaseRequisitionRow::query()->whereKey($rowIds)->pluck('purchase_requisition_id')->unique()->sort()->values()->all();
            $requisitions = PurchaseRequisition::query()->whereKey($rowRequisitionIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $rows = PurchaseRequisitionRow::query()->with(['article:id,number,name,is_stock_item', 'account:id,number', 'costCenter:id,code', 'project:id,code'])
                ->whereKey($rowIds)->lockForUpdate()->get();

            if ($rows->count() !== count($rowIds)) {
                throw ValidationException::withMessages(['convert' => __('En av raderna finns inte längre. Ladda om sidan och försök igen.')]);
            }

            $of = fn (PurchaseRequisitionRow $row): PurchaseRequisition => $requisitions->get($row->purchase_requisition_id) ?? throw new ModelNotFoundException;
            $rows = $rows->sortBy(fn (PurchaseRequisitionRow $row): string => str_pad((string) $of($row)->number, 12, '0', STR_PAD_LEFT).'.'.str_pad((string) $row->position, 6, '0', STR_PAD_LEFT))->values();

            foreach ($rows as $row) {
                $requisition = $of($row);

                if ($requisition->status !== PurchaseRequisitionStatus::Approved) {
                    throw ValidationException::withMessages(['convert' => __(':title är inte attesterad och kan inte beställas.', ['title' => $requisition->title()])]);
                }

                if ($row->isConverted()) {
                    throw ValidationException::withMessages(['convert' => __('Raden :name är redan beställd.', ['name' => $row->description])]);
                }

                if ($row->supplier_id !== null && $row->supplier_id !== $supplierId) {
                    throw ValidationException::withMessages(['convert' => __('Raden :name är avsedd för en annan leverantör.', ['name' => $row->description])]);
                }
            }

            $accounts = Account::query()->whereIn('number', array_values(array_filter(array_map(fn (mixed $detail): int => (int) ($detail['account'] ?? 0), $details))))->get()->keyBy('number');
            $input = [];

            foreach ($rows as $row) {
                $input[] = $this->orderRow($row, $of($row), (array) ($details[$row->id] ?? []), $accounts);
            }

            try {
                $order = ($this->save)($user, $company, [
                    'supplier_id' => $supplierId,
                    'our_reference' => mb_substr($user->name, 0, 100),
                    'rows' => $input,
                ], null, PurchaseOrderSource::Requisition);
            } catch (ValidationException $exception) {
                throw $this->explain($exception, $rows->all(), $requisitions);
            }

            $ordered = $rows->all();

            foreach ($order->rows->values() as $index => $orderRow) {
                $ordered[$index]->forceFill(['purchase_order_row_id' => $orderRow->id])->save();
            }

            foreach ($requisitions as $requisition) {
                $open = PurchaseRequisitionRow::query()->where('purchase_requisition_id', $requisition->id)->whereNull('purchase_order_row_id')->exists();

                if (! $open) {
                    $requisition->forceFill(['status' => PurchaseRequisitionStatus::Converted])->save();
                }
            }

            Audit::record('inventory.purchase_requisitions_converted', $order, [
                'number' => $order->number,
                'requisition_ids' => $requisitions->keys()->all(),
                'rows' => $rows->count(),
            ], $company, $user);

            return $order;
        }));
    }

    /**
     * One order row from a requisition row. A stock article keeps its article
     * and an empty price takes the article's purchase price; anything else
     * becomes a row without an article on the requested account.
     *
     * @param  array<string, mixed>  $detail
     * @param  Collection<int, Account>  $accounts
     * @return array<string, mixed>
     */
    private function orderRow(PurchaseRequisitionRow $row, PurchaseRequisition $requisition, array $detail, Collection $accounts): array
    {
        $price = isset($detail['price']) && trim((string) $detail['price']) !== ''
            ? trim((string) $detail['price'])
            : ($row->estimated_price_ore === null ? '' : Money::ofOre($row->estimated_price_ore)->format(withCurrency: false));
        $base = [
            'quantity' => Quantity::decimal($row->quantity_milli),
            'unit_id' => $row->unit_id,
            'price' => $price,
            'expected_on' => $requisition->needed_by?->toDateString(),
        ];

        if ($row->article !== null && $row->article->is_stock_item) {
            return [...$base, 'article_id' => $row->article_id, 'description' => $row->description];
        }

        $number = (int) ($detail['account'] ?? 0);
        $account = $number === 0 ? $row->account : $accounts->get($number);

        if ($account === null) {
            throw ValidationException::withMessages(['convert' => __('Ange konto för raden :name. Anmodan har inget konto och det behövs för det som inte är en lagerartikel.', ['name' => $row->description])]);
        }

        return [
            ...$base,
            'article_id' => null,
            'description' => $row->description,
            'account' => (string) $account->number,
            'cost_center' => $row->costCenter?->code,
            'project' => $row->project?->code,
        ];
    }

    /**
     * Puts the requisition and row in front of an order validation error, so the
     * buyer sees which request it concerns.
     *
     * @param  array<int, PurchaseRequisitionRow>  $rows
     * @param  Collection<int, PurchaseRequisition>  $requisitions
     */
    private function explain(ValidationException $exception, array $rows, Collection $requisitions): ValidationException
    {
        foreach ($exception->errors() as $key => $messages) {
            if (preg_match('/^rows\.(\d+)\./', $key, $match) === 1 && isset($rows[(int) $match[1]])) {
                $row = $rows[(int) $match[1]];

                return ValidationException::withMessages(['convert' => __(':title, raden :name: :message', ['title' => $requisitions->get($row->purchase_requisition_id)?->title(), 'name' => $row->description, 'message' => $messages[0]])]);
            }
        }

        return ValidationException::withMessages(['convert' => (string) ($exception->errors()[array_key_first($exception->errors())][0] ?? __('Ordern kunde inte skapas.'))]);
    }
}
