{{--
    A project's register details (ADR-081 gap 6) under its row in the
    project register: a summary, and the form while it is being edited.
--}}
@php
    use App\Domain\Projects\ProjectPriceForm;
    use App\Domain\Projects\ProjectStatus;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $manager = $project->manager !== null && in_array($project->manager_user_id, $memberIds, true) ? $project->manager : null;
@endphp

<div class="w-full basis-full text-sm text-gray-600" data-project-details="{{ $project->code }}">
    @if($detailsId === $project->id)
        <form wire:submit="saveDetails" class="mt-2 grid gap-4 rounded-xl bg-gray-50 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.field for="project_status" :label="__('Status')" error="status">
                <select id="project_status" class="{{ $input }} mt-1" wire:model="details.status">
                    @foreach(ProjectStatus::cases() as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="project_customer" :label="__('Kund')" error="customer_id">
                <select id="project_customer" class="{{ $input }} mt-1" wire:model="details.customer_id">
                    <option value="">{{ __('Ingen kund') }}</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->number }} {{ $customer->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="project_manager" :label="__('Projektledare')" error="manager_user_id">
                <select id="project_manager" class="{{ $input }} mt-1" wire:model="details.manager_user_id">
                    <option value="">{{ __('Ingen projektledare') }}</option>
                    @foreach($members as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="project_cost_center" :label="__('Kostnadsställe')" :hint="__('Föreslås på rader med projektet.')" error="default_cost_center">
                <select id="project_cost_center" class="{{ $input }} mt-1" wire:model="details.default_cost_center">
                    <option value="">{{ __('Inget kostnadsställe') }}</option>
                    @foreach($costCenters as $costCenter)
                        <option value="{{ $costCenter->code }}">{{ $costCenter->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="project_starts" :label="__('Startdatum')" error="starts_on">
                <input id="project_starts" type="date" class="{{ $input }} mt-1" wire:model="details.starts_on">
            </x-ui.field>
            <x-ui.field for="project_ends" :label="__('Slutdatum')" error="ends_on">
                <input id="project_ends" type="date" class="{{ $input }} mt-1" wire:model="details.ends_on">
            </x-ui.field>
            <x-ui.field for="project_price_form" :label="__('Prisform')" error="price_form">
                <select id="project_price_form" class="{{ $input }} mt-1" wire:model="details.price_form">
                    <option value="">{{ __('Inte angiven') }}</option>
                    @foreach(ProjectPriceForm::cases() as $form)
                        <option value="{{ $form->value }}">{{ $form->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="project_contract_sum" :label="__('Kontraktssumma, kr')" error="contract_sum">
                <input id="project_contract_sum" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="details.contract_sum" autocomplete="off">
            </x-ui.field>
            <x-ui.field for="project_estimated_cost" :label="__('Beräknad total kostnad, kr')" error="estimated_cost">
                <input id="project_estimated_cost" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="details.estimated_cost" autocomplete="off">
            </x-ui.field>
            <div class="flex items-end gap-3 sm:col-span-2 lg:col-span-3">
                <x-button wire:loading.attr="disabled">{{ __('Spara uppgifter') }}</x-button>
                <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelDetails">{{ __('Avbryt') }}</button>
            </div>
        </form>
    @else
        <p class="flex flex-wrap gap-x-4 gap-y-1">
            <x-ui.badge>{{ $project->status->label() }}</x-ui.badge>
            @if($project->customer !== null)
                <span>{{ __('Kund: :name', ['name' => $project->customer->name]) }}</span>
            @endif
            @if($manager !== null)
                <span>{{ __('Projektledare: :name', ['name' => $manager->name]) }}</span>
            @endif
            @if($project->price_form !== null)
                <span>{{ $project->price_form->label() }}</span>
            @endif
            @if($project->contract_sum_ore !== null)
                <span class="tabular-nums">{{ __('Kontraktssumma :amount', ['amount' => $project->contract_sum_ore->format()]) }}</span>
            @endif
            @if($project->starts_on !== null || $project->ends_on !== null)
                <span class="tabular-nums">{{ $project->starts_on?->toDateString() ?? '…' }} – {{ $project->ends_on?->toDateString() ?? '…' }}</span>
            @endif
            @if($project->defaultCostCenter !== null)
                <span>{{ __('Kostnadsställe: :name', ['name' => $project->defaultCostCenter->label()]) }}</span>
            @endif
        </p>
    @endif
</div>
