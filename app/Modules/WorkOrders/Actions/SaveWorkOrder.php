<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\TaxReduction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\NumberSeries;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\WorkOrders\Actions\Concerns\GuardsWorkOrders;
use App\Modules\WorkOrders\Domain\PriceForm;
use App\Modules\WorkOrders\Models\LabourKind;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderAssignment;
use App\Modules\WorkOrders\Models\WorkOrderBuyer;
use App\Modules\WorkOrders\Models\WorkOrderInvoice;
use App\Modules\WorkOrders\Support\Input;
use App\Modules\WorkOrders\WorkOrdersSettings;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a work order (ADR-074 §2).
 *
 * - The number comes from its own series; by default a project is created
 *   with the number as code (O2), so supplier invoice rows, payroll time
 *   and vouchers can point at it.
 * - Reverse charge is copied from the customer on creation and locked
 *   (AO-R20); ROT or RUT and reverse charge are refused together.
 * - ROT or RUT needs a private person with personnummer, and ROT the
 *   property designation or the association's organisation number and
 *   apartment number (HFL 9 a §, AO-R15). Several buyers share the
 *   deduction by shares adding up to 100 % (O6).
 * - The tax reduction, VAT and price form are fixed once an invoice is made.
 */
class SaveWorkOrder
{
    use GuardsWorkOrders;

    /**
     * @param  array<string, mixed>  $input  customer_id, title, description, site_address_line1, site_postal_code,
     *                                       site_city, property_designation, housing_association_org_number,
     *                                       apartment_number, access_notes, price_form, contract_sum, contract_labour
     *                                       (kronor ex VAT), approximate_price (kronor incl. VAT), approximate_limit
     *                                       (percent), material_markup (percent), vat_rate, rates (labour kind id =>
     *                                       kronor), tax_reduction, buyers (list of customer_id, share (percent)),
     *                                       is_construction, standard_contract, planned_start, planned_end, user_ids,
     *                                       create_project (optional: the company's setting)
     */
    public function __invoke(User $user, Company $company, array $input, ?WorkOrder $workOrder = null, ?SalesDocument $origin = null): WorkOrder
    {
        $this->guard($user, $company);

        $validated = Validator::make($input, [
            'customer_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'site_address_line1' => ['nullable', 'string', 'max:255'],
            'site_postal_code' => ['nullable', 'string', 'max:10'],
            'site_city' => ['nullable', 'string', 'max:100'],
            'property_designation' => ['nullable', 'string', 'max:255'],
            'housing_association_org_number' => ['nullable', 'string', 'max:13'],
            'apartment_number' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'access_notes' => ['nullable', 'string', 'max:2000'],
            'price_form' => ['required', Rule::enum(PriceForm::class)],
            'contract_sum' => ['nullable', 'string', 'max:20'],
            'contract_labour' => ['nullable', 'string', 'max:20'],
            'approximate_price' => ['nullable', 'string', 'max:20'],
            'approximate_limit' => ['nullable', 'string', 'max:10'],
            'material_markup' => ['nullable', 'string', 'max:10'],
            'vat_rate' => ['nullable', 'integer', Rule::in(InvoiceCalculator::VAT_RATES)],
            'rates' => ['nullable', 'array'],
            'tax_reduction' => ['nullable', Rule::in([TaxReduction::ROT, TaxReduction::RUT])],
            'buyers' => ['nullable', 'array'],
            'buyers.*.customer_id' => ['required', 'integer'],
            'buyers.*.share' => ['required', 'string', 'max:10'],
            'is_construction' => ['nullable', 'boolean'],
            'standard_contract' => ['nullable', 'string', 'max:100'],
            'planned_start' => ['nullable', 'date_format:Y-m-d'],
            'planned_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:planned_start'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'create_project' => ['nullable', 'boolean'],
        ], [
            'customer_id.required' => __('Välj en kund.'),
            'title.required' => __('Skriv vad arbetet gäller.'),
            'apartment_number.regex' => __('Lägenhetsnumret är fyra siffror, till exempel 1102.'),
            'planned_end.after_or_equal' => __('Slutdatumet kan inte vara före startdatumet.'),
        ], [
            'title' => __('rubrik'),
        ])->validate();

        if ($workOrder !== null && ! $workOrder->status->isOpen()) {
            throw ValidationException::withMessages(['work_order' => __('Arbetsordern är avslutad och kan inte ändras.')]);
        }

        $priceForm = PriceForm::from((string) $validated['price_form']);
        $contractSum = Input::money($validated['contract_sum'] ?? null, 'contract_sum');
        $contractLabour = Input::money($validated['contract_labour'] ?? null, 'contract_labour');
        $approximate = Input::money($validated['approximate_price'] ?? null, 'approximate_price');
        $limit = Input::percentBp($validated['approximate_limit'] ?? null, 'approximate_limit');
        $markup = Input::percentBp($validated['material_markup'] ?? null, 'material_markup') ?? WorkOrdersSettings::markupBp($company);
        $taxReduction = $validated['tax_reduction'] ?? null;

        if ($priceForm === PriceForm::Fixed && ($contractSum === null || $contractSum <= 0)) {
            throw ValidationException::withMessages(['contract_sum' => __('Skriv det fasta priset exklusive moms.')]);
        }

        if ($priceForm === PriceForm::Fixed && $contractLabour !== null && $contractLabour > (int) $contractSum) {
            throw ValidationException::withMessages(['contract_labour' => __('Arbetskostnaden kan inte vara större än det fasta priset.')]);
        }

        if ($priceForm === PriceForm::Fixed && $taxReduction !== null && ($contractLabour === null || $contractLabour <= 0)) {
            throw ValidationException::withMessages(['contract_labour' => __('Skriv hur mycket av det fasta priset som är arbetskostnad. Bara arbetet ger :type.', ['type' => TaxReduction::label($taxReduction)])]);
        }

        if ($priceForm === PriceForm::Estimate && ($approximate === null || $approximate <= 0)) {
            throw ValidationException::withMessages(['approximate_price' => __('Skriv det ungefärliga priset inklusive moms.')]);
        }

        return DB::transaction(fn (): WorkOrder => CurrentCompany::run($company, function () use ($validated, $input, $user, $company, $workOrder, $origin, $priceForm, $contractSum, $contractLabour, $approximate, $limit, $markup, $taxReduction): WorkOrder {
            $customer = Customer::query()->find((int) $validated['customer_id'])
                ?? throw ValidationException::withMessages(['customer_id' => __('Välj en kund.')]);

            $customer->ensureNotRestricted('customer_id');

            if ($workOrder !== null) {
                WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->first();
            }

            $invoiced = $workOrder !== null && WorkOrderInvoice::query()->where('work_order_id', $workOrder->id)->exists();

            if ($invoiced && ($workOrder->customer_id !== $customer->id || $workOrder->tax_reduction !== $taxReduction || $workOrder->price_form !== $priceForm || $workOrder->vat_rate !== (int) ($validated['vat_rate'] ?? $workOrder->vat_rate))) {
                throw ValidationException::withMessages(['work_order' => __('Det finns fakturor från arbetsordern. Kund, pris­form, moms och ROT eller RUT kan inte längre ändras.')]);
            }

            // Copied from the customer when the work order is created, then locked (AO-R20).
            $reverseCharge = $workOrder !== null ? $workOrder->reverse_charge : $customer->reverse_charge_construction;

            if ($workOrder !== null && $workOrder->customer_id !== $customer->id) {
                $reverseCharge = $customer->reverse_charge_construction;
            }

            $this->ensureTaxReduction($validated, $taxReduction, $customer, $reverseCharge);
            $buyers = $taxReduction === null ? [] : $this->buyers($validated, $customer);
            $rates = $this->rates((array) ($validated['rates'] ?? []));
            $isNew = $workOrder === null;

            $workOrder ??= new WorkOrder(['created_by' => $user->id, 'sales_document_id' => $origin?->id]);
            $workOrder->fill([
                'customer_id' => $customer->id,
                'title' => trim((string) $validated['title']),
                'description' => $validated['description'] ?? null,
                'site_address_line1' => $validated['site_address_line1'] ?? null,
                'site_postal_code' => $validated['site_postal_code'] ?? null,
                'site_city' => $validated['site_city'] ?? null,
                'property_designation' => $taxReduction === TaxReduction::ROT || $taxReduction === null ? ($validated['property_designation'] ?? null) : null,
                'housing_association_org_number' => $validated['housing_association_org_number'] ?? null,
                'apartment_number' => $validated['apartment_number'] ?? null,
                'access_notes' => filled($validated['access_notes'] ?? null) ? (string) $validated['access_notes'] : null,
                'price_form' => $priceForm->value,
                'contract_sum_ore' => $priceForm === PriceForm::Fixed ? $contractSum : null,
                'contract_labour_ore' => $priceForm === PriceForm::Fixed ? $contractLabour : null,
                'approximate_price_ore' => $priceForm === PriceForm::Estimate ? $approximate : null,
                'approximate_limit_bp' => $priceForm === PriceForm::Estimate ? $limit : null,
                'material_markup_bp' => $markup,
                'vat_rate' => (int) ($validated['vat_rate'] ?? $workOrder->vat_rate ?? 2500),
                'rates' => $rates,
                'tax_reduction' => $taxReduction,
                'reverse_charge' => $reverseCharge,
                'is_construction' => (bool) ($validated['is_construction'] ?? $workOrder->is_construction ?? true),
                'standard_contract' => filled($validated['standard_contract'] ?? null) ? (string) $validated['standard_contract'] : null,
                'planned_start' => $validated['planned_start'] ?? null,
                'planned_end' => $validated['planned_end'] ?? null,
            ]);

            if ($isNew) {
                $workOrder->forceFill(['number' => NumberSeries::take(NumberSeries::WORK_ORDERS)]);
            }

            $workOrder->save();

            if ($isNew && (bool) ($validated['create_project'] ?? WorkOrdersSettings::autoProject($company))) {
                $workOrder->forceFill(['project_id' => $this->project($workOrder)->id])->save();
            }

            $this->syncBuyers($workOrder, $buyers);

            if (array_key_exists('user_ids', $input)) {
                $this->syncUsers($company, $workOrder, array_map(intval(...), (array) ($validated['user_ids'] ?? [])));
            }

            Audit::record($isNew ? 'work_orders.created' : 'work_orders.updated', $workOrder, [
                'number' => $workOrder->number,
                'customer_id' => $customer->id,
                'price_form' => $priceForm->value,
                'tax_reduction' => $taxReduction,
                'reverse_charge' => $reverseCharge,
                'project_id' => $workOrder->project_id,
                'sales_document_id' => $workOrder->sales_document_id,
            ], $company, $user);

            return $workOrder->refresh();
        }));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function ensureTaxReduction(array $validated, ?string $taxReduction, Customer $customer, bool $reverseCharge): void
    {
        if ($taxReduction === null) {
            return;
        }

        if ($reverseCharge) {
            throw ValidationException::withMessages(['tax_reduction' => __('ROT- och RUT-avdrag kan inte kombineras med omvänd betalningsskyldighet. Kunden är ett byggföretag och köparna av ROT och RUT är privatpersoner.')]);
        }

        if (blank($customer->personal_number)) {
            throw ValidationException::withMessages(['tax_reduction' => __('ROT och RUT gäller privatpersoner. Fyll i kundens personnummer.')]);
        }

        $property = filled($validated['property_designation'] ?? null);
        $apartment = filled($validated['housing_association_org_number'] ?? null) && filled($validated['apartment_number'] ?? null);

        if ($taxReduction === TaxReduction::ROT && ! $property && ! $apartment) {
            throw ValidationException::withMessages(['property_designation' => __('Fyll i fastighetsbeteckningen, eller bostadsrättsföreningens organisationsnummer och lägenhetsnummer, för ROT-avdraget.')]);
        }
    }

    /**
     * The buyers sharing the deduction (O6); none means the customer alone.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array{customer_id: int, share_bp: int}>
     */
    private function buyers(array $validated, Customer $customer): array
    {
        $buyers = [];

        foreach ((array) ($validated['buyers'] ?? []) as $index => $buyer) {
            $share = Input::percentBp($buyer['share'] ?? null, "buyers.{$index}.share") ?? 0;
            $each = Customer::query()->find((int) $buyer['customer_id'])
                ?? throw ValidationException::withMessages(["buyers.{$index}.customer_id" => __('Köparen finns inte i kundregistret.')]);

            if (blank($each->personal_number)) {
                throw ValidationException::withMessages(["buyers.{$index}.customer_id" => __('ROT och RUT gäller privatpersoner. Fyll i personnummer för :name.', ['name' => $each->name])]);
            }

            $buyers[] = ['customer_id' => $each->id, 'share_bp' => $share];
        }

        if (count($buyers) < 2) {
            return [];
        }

        if (array_sum(array_column($buyers, 'share_bp')) !== 10_000) {
            throw ValidationException::withMessages(['buyers' => __('Köparnas andelar ska tillsammans vara 100 procent.')]);
        }

        $ids = array_column($buyers, 'customer_id');

        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['buyers' => __('Samma köpare kan bara finnas med en gång.')]);
        }

        if (! in_array($customer->id, $ids, true)) {
            throw ValidationException::withMessages(['buyers' => __('Arbetsorderns kund ska vara en av köparna.')]);
        }

        return $buyers;
    }

    /**
     * @param  array<int|string, mixed>  $input  labour kind id => kronor
     * @return array<string, int>
     */
    private function rates(array $input): array
    {
        $kinds = LabourKind::query()->whereKey(array_map(intval(...), array_keys($input)))->pluck('id')->all();
        $rates = [];

        foreach ($input as $kindId => $typed) {
            if (! in_array((int) $kindId, $kinds, true)) {
                continue;
            }

            $ore = Input::money($typed, "rates.{$kindId}");

            if ($ore !== null) {
                $rates[(string) $kindId] = $ore;
            }
        }

        return $rates;
    }

    /** A project with the work order's number as code (O2), or the existing one with that code. */
    private function project(WorkOrder $workOrder): Project
    {
        $code = "AO{$workOrder->number}";

        return Project::query()->firstOrCreate(['code' => $code], ['name' => mb_substr(__('Arbetsorder :number', ['number' => $workOrder->number]).' '.$workOrder->title, 0, 255)]);
    }

    /** @param  list<array{customer_id: int, share_bp: int}>  $buyers */
    private function syncBuyers(WorkOrder $workOrder, array $buyers): void
    {
        WorkOrderBuyer::query()->where('work_order_id', $workOrder->id)->delete();

        foreach ($buyers as $buyer) {
            WorkOrderBuyer::create(['work_order_id' => $workOrder->id, ...$buyer]);
        }
    }

    /**
     * Only members of the company can be assigned.
     *
     * @param  array<int, int>  $userIds
     */
    private function syncUsers(Company $company, WorkOrder $workOrder, array $userIds): void
    {
        $members = $company->allUsers()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $userIds = array_values(array_intersect(array_unique($userIds), $members));

        WorkOrderAssignment::query()->where('work_order_id', $workOrder->id)->whereNotIn('user_id', $userIds)->delete();

        foreach ($userIds as $userId) {
            WorkOrderAssignment::query()->firstOrCreate(['work_order_id' => $workOrder->id, 'user_id' => $userId]);
        }
    }
}
