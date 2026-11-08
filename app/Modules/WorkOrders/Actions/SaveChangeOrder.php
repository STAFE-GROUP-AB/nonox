<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\WorkOrders\Actions\Concerns\GuardsWorkOrders;
use App\Modules\WorkOrders\Domain\ChangeOrderBasis;
use App\Modules\WorkOrders\Domain\ChangeOrderStatus;
use App\Modules\WorkOrders\Domain\PriceForm;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Support\Input;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a draft ÄTA (ADR-074 §4, rule sheet §D). Numbered per
 * work order: ÄTA 1, 2 … A field worker may start one on site with what
 * and why; pricing it needs work-orders:manage. VAT treatment is never the
 * ÄTA's own: it follows the work order (AO-R20). ROT/RUT follows the work
 * order and can be switched off for the ÄTA, never moved to another
 * property (AO-R17).
 */
class SaveChangeOrder
{
    use GuardsWorkOrders;

    /**
     * @param  array<string, mixed>  $input  title, description, basis, price_form, labour, material, other (kronor ex VAT),
     *                                       limit (kronor incl. VAT), time_effect, payment_terms, tax_reduction_applies, expires_on
     */
    public function __invoke(User $user, Company $company, WorkOrder $workOrder, array $input, ?ChangeOrder $changeOrder = null): ChangeOrder
    {
        $this->guardReport($user, $company, $workOrder);
        $manager = $user->hasTeamPermission($company, WorkOrdersModule::MANAGE);

        $validated = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'basis' => ['nullable', Rule::enum(ChangeOrderBasis::class)],
            'price_form' => ['nullable', Rule::enum(PriceForm::class)],
            'labour' => ['nullable', 'string', 'max:20'],
            'material' => ['nullable', 'string', 'max:20'],
            'other' => ['nullable', 'string', 'max:20'],
            'limit' => ['nullable', 'string', 'max:20'],
            'time_effect' => ['nullable', 'string', 'max:255'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'tax_reduction_applies' => ['nullable', 'boolean'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'title.required' => __('Skriv vad ändringen gäller.'),
            'description.required' => __('Beskriv vad som ska göras.'),
        ])->validate();

        if ($changeOrder !== null && $changeOrder->status !== ChangeOrderStatus::Draft) {
            throw ValidationException::withMessages(['change_order' => __('ÄTA:t är :status och kan inte ändras.', ['status' => mb_strtolower($changeOrder->status->label())])]);
        }

        // Once the office has priced it, only the office changes what it says (review P3-3).
        if ($changeOrder !== null && ! $manager && ($changeOrder->netOre() > 0 || $changeOrder->price_form !== PriceForm::Fixed)) {
            throw ValidationException::withMessages(['change_order' => __('ÄTA:t är prissatt. Be kontoret ändra det.')]);
        }

        $priced = $manager ? [
            'price_form' => $validated['price_form'] ?? PriceForm::Fixed->value,
            'labour_ore' => Input::money($validated['labour'] ?? null, 'labour') ?? 0,
            'material_ore' => Input::money($validated['material'] ?? null, 'material') ?? 0,
            'other_ore' => Input::money($validated['other'] ?? null, 'other') ?? 0,
            'limit_ore' => Input::money($validated['limit'] ?? null, 'limit'),
            'payment_terms' => $validated['payment_terms'] ?? null,
            'tax_reduction_applies' => $workOrder->tax_reduction !== null && (bool) ($validated['tax_reduction_applies'] ?? true),
            'expires_on' => $validated['expires_on'] ?? null,
        ] : [];

        if (($priced['price_form'] ?? null) === PriceForm::Estimate->value && ($priced['limit_ore'] ?? null) === null) {
            throw ValidationException::withMessages(['limit' => __('Skriv den övre gränsen för det ungefärliga priset, inklusive moms.')]);
        }

        return DB::transaction(fn (): ChangeOrder => CurrentCompany::run($company, function () use ($validated, $user, $company, $workOrder, $changeOrder, $priced): ChangeOrder {
            $workOrder = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if (! $workOrder->status->isOpen()) {
                throw ValidationException::withMessages(['work_order' => __('Arbetsordern är avslutad.')]);
            }

            $isNew = $changeOrder === null;

            $changeOrder ??= new ChangeOrder([
                'work_order_id' => $workOrder->id,
                'created_by' => $user->id,
                'price_form' => PriceForm::Fixed->value,
                'tax_reduction_applies' => $workOrder->tax_reduction !== null,
            ]);
            $changeOrder->fill([
                'title' => trim((string) $validated['title']),
                'description' => trim((string) $validated['description']),
                'basis' => $validated['basis'] ?? ChangeOrderBasis::ChangeOrdered->value,
                'time_effect' => $validated['time_effect'] ?? null,
                ...$priced,
            ]);

            if ($isNew) {
                $changeOrder->forceFill(['number' => (int) ChangeOrder::query()->where('work_order_id', $workOrder->id)->max('number') + 1]);
            }

            $changeOrder->save();

            Audit::record($isNew ? 'work_orders.change_order_created' : 'work_orders.change_order_updated', $changeOrder, [
                'work_order_id' => $workOrder->id,
                'number' => $changeOrder->number,
                'basis' => $changeOrder->basis->value,
                'price_form' => $changeOrder->price_form->value,
                'net_ore' => $changeOrder->netOre(),
            ], $company, $user);

            return $changeOrder;
        }));
    }
}
