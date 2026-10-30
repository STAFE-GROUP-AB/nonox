@php
    use App\Modules\Payroll\Domain\PersonalNumber;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $types = ['TILLSVIDARE' => __('Tillsvidare'), 'ALLMAN_VISSTID' => __('Allmän visstid'), 'VIKARIAT' => __('Vikariat'), 'SASONG' => __('Säsong'), 'PROVANSTALLNING' => __('Provanställning'), 'TIMANSTALLD' => __('Timanställd')];
    $residences = ['SE' => __('Bosatt i Sverige (skattetabell)'), 'NON_RESIDENT_SINK' => __('Bosatt utomlands, SINK'), 'NON_RESIDENT_A_SINK' => __('Bosatt utomlands, A-SINK')];
@endphp

<div class="space-y-6">
    @if($showForm)
        <x-ui.card :title="$editingId ? __('Ändra anställd') : __('Ny anställd')">
            <form wire:submit="save" class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field for="employee_first_name" :label="__('Förnamn')" error="first_name"><input id="employee_first_name" type="text" class="{{ $input }} mt-1" wire:model="form.first_name"></x-ui.field>
                    <x-ui.field for="employee_last_name" :label="__('Efternamn')" error="last_name"><input id="employee_last_name" type="text" class="{{ $input }} mt-1" wire:model="form.last_name"></x-ui.field>
                    <x-ui.field for="employee_personal_number" :label="__('Personnummer')" error="personal_number" :hint="__('Tolv siffror, ÅÅÅÅMMDD-NNNN. Sparas krypterat.')"><input id="employee_personal_number" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.personal_number" autocomplete="off"></x-ui.field>
                    <x-ui.field for="employee_email" :label="__('E-post')" error="email"><input id="employee_email" type="email" class="{{ $input }} mt-1" wire:model="form.email"></x-ui.field>
                    <x-ui.field for="employee_address" :label="__('Adress')" error="address" class="sm:col-span-2"><input id="employee_address" type="text" class="{{ $input }} mt-1" wire:model="form.address"></x-ui.field>
                    <x-ui.field for="employee_postal_code" :label="__('Postnummer')" error="postal_code"><input id="employee_postal_code" type="text" class="{{ $input }} mt-1" wire:model="form.postal_code"></x-ui.field>
                    <x-ui.field for="employee_city" :label="__('Ort')" error="city"><input id="employee_city" type="text" class="{{ $input }} mt-1" wire:model="form.city"></x-ui.field>
                    <x-ui.field for="employee_bank_clearing" :label="__('Clearingnummer')" error="bank_clearing"><input id="employee_bank_clearing" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.bank_clearing" autocomplete="off"></x-ui.field>
                    <x-ui.field for="employee_bank_account" :label="__('Kontonummer för lön')" error="bank_account" :hint="__('Sparas krypterat.')"><input id="employee_bank_account" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.bank_account" autocomplete="off"></x-ui.field>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field for="employee_tax_residence" :label="__('Skatt')" error="tax_residence" class="sm:col-span-3">
                        <select id="employee_tax_residence" class="{{ $input }} mt-1" wire:model.live="form.tax_residence">
                            @foreach($residences as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    @if(($form['tax_residence'] ?? 'SE') === 'SE')
                        <x-ui.field for="employee_tax_table" :label="__('Skattetabell')" error="tax_table" :hint="__('Från den anställdes skattebesked.')"><input id="employee_tax_table" type="number" class="{{ $input }} mt-1" wire:model="form.tax_table"></x-ui.field>
                        <x-ui.field for="employee_tax_column" :label="__('Kolumn')" error="tax_column"><input id="employee_tax_column" type="number" class="{{ $input }} mt-1" wire:model="form.tax_column"></x-ui.field>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field for="employee_start" :label="__('Anställd från')" error="start_date"><input id="employee_start" type="date" class="{{ $input }} mt-1" wire:model="form.start_date"></x-ui.field>
                    <x-ui.field for="employee_type" :label="__('Anställningsform')" error="employment_type">
                        <select id="employee_type" class="{{ $input }} mt-1" wire:model="form.employment_type">
                            @foreach($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="employee_position" :label="__('Befattning')" error="position_title"><input id="employee_position" type="text" class="{{ $input }} mt-1" wire:model="form.position_title" placeholder="{{ __('Till exempel Kock') }}"></x-ui.field>
                    <x-ui.field for="employee_salary_form" :label="__('Lön per')" error="salary_form">
                        <select id="employee_salary_form" class="{{ $input }} mt-1" wire:model="form.salary_form">
                            <option value="MONTHLY">{{ __('Månad') }}</option>
                            <option value="HOURLY">{{ __('Timme') }}</option>
                        </select>
                    </x-ui.field>
                    <x-ui.field for="employee_salary" :label="__('Lön, kronor')" error="salary"><input id="employee_salary" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.salary"></x-ui.field>
                    <x-ui.field for="employee_rate" :label="__('Sysselsättningsgrad, %')" error="employment_rate"><input id="employee_rate" type="number" class="{{ $input }} mt-1" wire:model="form.employment_rate"></x-ui.field>
                    <x-ui.field for="employee_cost_center" :label="__('Kostnadsställe (frivilligt)')" error="cost_center"><input id="employee_cost_center" type="text" class="{{ $input }} mt-1" wire:model="form.cost_center"></x-ui.field>
                </div>

                <div class="flex items-center gap-4">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="text-sm text-gray-600">{{ $employees->count() === 1 ? __('1 anställd') : __(':count anställda', ['count' => $employees->count()]) }}</p>
            @if($canManage && ! $showForm)
                <x-button type="button" wire:click="create">{{ __('Ny anställd') }}</x-button>
            @endif
        </div>

        @if($employees->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga anställda ännu')">{{ __('Lägg upp personalen med skattetabell och kontonummer. Uppgifterna används i lönekörningarna och arbetsgivardeklarationen.') }}</x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100">
                @foreach($employees as $employee)
                    @php($employment = $employee->employments->first())
                    <li wire:key="employee-{{ $employee->id }}" class="flex flex-wrap items-center gap-4 py-3">
                        <span class="w-12 flex-none font-semibold tabular-nums text-gray-900">{{ $employee->employee_number }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-gray-900">{{ $employee->first_name }} {{ $employee->last_name }}</span>
                            <span class="block text-sm text-gray-600 tabular-nums">{{ collect([PersonalNumber::masked($employee->personal_number), $employment?->position_title, $employment ? $types[$employment->employment_type] ?? null : null])->filter()->implode(' · ') }}</span>
                        </span>
                        @if($employment)
                            <span class="text-right text-sm tabular-nums">
                                <x-ui.amount :money="$employment->salary_form === 'HOURLY' ? $employment->hourly_rate_ore : $employment->monthly_salary_ore" />
                                <span class="block text-xs text-gray-500">{{ $employment->salary_form === 'HOURLY' ? __('per timme') : __('per månad') }}</span>
                            </span>
                        @endif
                        @if($canManage)
                            <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="edit({{ $employee->id }})">{{ __('Ändra') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
