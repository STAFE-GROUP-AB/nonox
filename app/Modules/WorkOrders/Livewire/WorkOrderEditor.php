<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Livewire;

use App\Models\Customer;
use App\Models\SalesDocument;
use App\Modules\WorkOrders\Actions\SaveWorkOrder;
use App\Modules\WorkOrders\Domain\PriceForm;
use App\Modules\WorkOrders\Livewire\Concerns\WorkOrdersPage;
use App\Modules\WorkOrders\Models\LabourKind;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\WorkOrdersSettings;
use App\Support\Money\Money;
use App\Support\Toast;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Creating or changing a work order: customer, title, site, price form,
 * ROT or RUT with the property and the buyers, rates, the people assigned.
 * ?offert=<id> starts from a quote or order (ADR-074 §2).
 */
class WorkOrderEditor extends Component
{
    use WorkOrdersPage;

    #[Locked]
    public ?int $workOrderId = null;

    #[Locked]
    public ?string $salesDocumentId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(?int $workOrderId = null): void
    {
        abort_unless($this->can('manage'), 403);

        $this->form = [
            'customer_id' => '', 'title' => '', 'description' => '', 'site_address_line1' => '', 'site_postal_code' => '', 'site_city' => '',
            'property_designation' => '', 'housing_association_org_number' => '', 'apartment_number' => '', 'access_notes' => '',
            'price_form' => PriceForm::TimeAndMaterial->value, 'contract_sum' => '', 'contract_labour' => '', 'approximate_price' => '',
            'approximate_limit' => '', 'material_markup' => '', 'vat_rate' => 2500, 'tax_reduction' => '', 'is_construction' => true,
            'standard_contract' => '', 'planned_start' => '', 'planned_end' => '', 'user_ids' => [], 'rates' => [], 'buyers' => [],
        ];

        if ($workOrderId !== null) {
            $workOrder = $this->findWorkOrder($workOrderId);
            $workOrder->load(['assignments', 'buyers']);
            $this->workOrderId = $workOrder->id;
            $money = fn (?Money $value): string => $value === null ? '' : $value->format(false);

            $this->form = [
                ...$this->form,
                'customer_id' => (string) $workOrder->customer_id,
                'title' => $workOrder->title,
                'description' => (string) $workOrder->description,
                'site_address_line1' => (string) $workOrder->site_address_line1,
                'site_postal_code' => (string) $workOrder->site_postal_code,
                'site_city' => (string) $workOrder->site_city,
                'property_designation' => (string) $workOrder->property_designation,
                'housing_association_org_number' => (string) $workOrder->housing_association_org_number,
                'apartment_number' => (string) $workOrder->apartment_number,
                'access_notes' => (string) $workOrder->access_notes,
                'price_form' => $workOrder->price_form->value,
                'contract_sum' => $money($workOrder->contract_sum_ore),
                'contract_labour' => $money($workOrder->contract_labour_ore),
                'approximate_price' => $money($workOrder->approximate_price_ore),
                'approximate_limit' => $workOrder->approximate_limit_bp === null ? '' : (string) ($workOrder->approximate_limit_bp / 100),
                'material_markup' => (string) ($workOrder->material_markup_bp / 100),
                'vat_rate' => $workOrder->vat_rate,
                'tax_reduction' => (string) $workOrder->tax_reduction,
                'is_construction' => $workOrder->is_construction,
                'standard_contract' => (string) $workOrder->standard_contract,
                'planned_start' => (string) $workOrder->planned_start?->toDateString(),
                'planned_end' => (string) $workOrder->planned_end?->toDateString(),
                'user_ids' => $workOrder->assignments->pluck('user_id')->map(fn (int $id): string => (string) $id)->all(),
                'rates' => collect($workOrder->rates ?? [])->map(fn (int $ore): string => Money::ofOre($ore)->format(false))->all(),
                'buyers' => $workOrder->buyers->map(fn ($buyer): array => ['customer_id' => (string) $buyer->customer_id, 'share' => (string) ($buyer->share_bp / 100)])->all(),
            ];

            return;
        }

        // ?offert=<id>: an accepted quote or an order becomes a work order (ADR-074 §2).
        $origin = request()->query('offert');

        if (is_string($origin) && ($document = SalesDocument::query()->find($origin)) instanceof SalesDocument) {
            $this->salesDocumentId = $document->id;
            $this->form['customer_id'] = (string) $document->customer_id;
            $this->form['title'] = ($document->kind === SalesDocument::QUOTE ? __('Offert :number', ['number' => $document->number]) : __('Order :number', ['number' => $document->number]));
            $this->form['description'] = (string) $document->notes;
            $this->form['price_form'] = PriceForm::Fixed->value;
            $this->form['contract_sum'] = $document->net_ore->format(false);
        }

        $this->form['material_markup'] = (string) (WorkOrdersSettings::markupBp($this->company()) / 100);
    }

    public function addBuyer(): void
    {
        $this->form['buyers'][] = ['customer_id' => '', 'share' => ''];
    }

    public function removeBuyer(int $index): void
    {
        unset($this->form['buyers'][$index]);
        $this->form['buyers'] = array_values($this->form['buyers']);
    }

    public function save(SaveWorkOrder $save): void
    {
        $workOrder = $this->workOrderId === null ? null : $this->findWorkOrder($this->workOrderId);
        $origin = $this->salesDocumentId === null ? null : SalesDocument::query()->find($this->salesDocumentId);
        $saved = null;

        $input = [
            ...$this->form,
            'customer_id' => $this->form['customer_id'] === '' ? null : (int) $this->form['customer_id'],
            'tax_reduction' => $this->form['tax_reduction'] === '' ? null : $this->form['tax_reduction'],
            'vat_rate' => (int) $this->form['vat_rate'],
            'planned_start' => $this->form['planned_start'] ?: null,
            'planned_end' => $this->form['planned_end'] ?: null,
            'buyers' => array_values(array_filter((array) $this->form['buyers'], fn (array $buyer): bool => $buyer['customer_id'] !== '')),
            'user_ids' => array_map(intval(...), (array) $this->form['user_ids']),
        ];

        $ok = $this->attempt(function () use ($save, $input, $workOrder, $origin, &$saved): void {
            $saved = $save($this->user(), $this->company(), $input, $workOrder, $origin instanceof SalesDocument ? $origin : null);
        }, '');

        if (! $ok || ! $saved instanceof WorkOrder) {
            return;
        }

        Toast::flash($workOrder === null ? __('Arbetsorder :number är skapad.', ['number' => $saved->number]) : __('Arbetsordern är sparad.'));
        $this->redirectRoute('workOrders.show', $saved->id);
    }

    public function render(): View
    {
        return view('work-orders::livewire.editor', [
            'customers' => Customer::query()->where('is_active', true)->whereNull('processing_restricted_at')->orderBy('name')->get(['id', 'name', 'reverse_charge_construction']),
            'kinds' => LabourKind::query()->where('is_active', true)->orderBy('position')->get(),
            'members' => $this->company()->allUsers()->sortBy('name')->values(),
            'priceForms' => PriceForm::cases(),
            'contracts' => WorkOrdersSettings::STANDARD_CONTRACTS,
            'locked' => $this->workOrderId !== null,
        ]);
    }
}
