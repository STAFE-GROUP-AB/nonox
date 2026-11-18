@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $starts = [
        App\Actions\Budget\CreateBudgetVersion::EMPTY => [__('Tom budget'), __('Börja från noll och fyll i raderna själv.')],
        App\Actions\Budget\CreateBudgetVersion::LAST_YEAR => [__('Som i fjol'), __('Förra årets utfall per konto och månad, avrundat till hela kronor.')],
        App\Actions\Budget\CreateBudgetVersion::COPY => [__('Kopia av en budget'), __('Alla rader från en annan budget, också en låst.')],
    ];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap gap-3">
        <a href="{{ route('accounting.budgets.report') }}" wire:navigate class="inline-flex items-center rounded-full bg-white px-4 py-2 text-sm font-semibold text-ink ring-1 ring-stone-200 hover:ring-stone-300">{{ __('Budget mot utfall') }}</a>
    </div>

    <x-ui.card :title="__('Budgetar')">
        <x-slot:cta>
            @if($canManage && ! $creating)
                <x-button type="button" wire:click="create">{{ __('Ny budget') }}</x-button>
            @endif
        </x-slot:cta>

        @if($creating)
            <form wire:submit="save" class="mb-6 space-y-4 rounded-2xl bg-paper p-5 ring-1 ring-stone-200">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field for="budget_name" :label="__('Namn')" error="name">
                        <input id="budget_name" type="text" maxlength="60" class="{{ $input }} mt-1" wire:model="form.name">
                    </x-ui.field>
                    <x-ui.field for="budget_year" :label="__('Räkenskapsår')" error="fiscal_year_id">
                        <select id="budget_year" class="{{ $input }} mt-1" wire:model="form.fiscal_year_id">
                            @foreach($fiscalYears as $year)
                                <option value="{{ $year->id }}">{{ $year->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                </div>
                <fieldset>
                    <legend class="text-sm font-medium text-gray-700">{{ __('Börja med') }}</legend>
                    <div class="mt-2 grid gap-3 sm:grid-cols-3">
                        @foreach($starts as $value => [$label, $description])
                            <label wire:key="start-{{ $value }}" @class([
                                'flex cursor-pointer flex-col gap-1 rounded-2xl bg-white p-4 ring-1 transition',
                                'ring-2 ring-ink' => $form['start'] === $value,
                                'ring-stone-200 hover:ring-stone-300' => $form['start'] !== $value,
                            ])>
                                <span class="flex items-center gap-2 font-semibold text-ink"><input type="radio" class="text-ink focus:ring-accent-500" value="{{ $value }}" wire:model.live="form.start">{{ $label }}</span>
                                <span class="text-xs text-gray-600">{{ $description }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('start')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
                </fieldset>
                @if($form['start'] !== App\Actions\Budget\CreateBudgetVersion::EMPTY)
                    <div class="grid gap-4 sm:grid-cols-2">
                        @if($form['start'] === App\Actions\Budget\CreateBudgetVersion::COPY)
                            <x-ui.field for="budget_source" :label="__('Budget att kopiera')" error="source_version_id">
                                <select id="budget_source" class="{{ $input }} mt-1" wire:model="form.source_version_id">
                                    <option value="">{{ __('Välj budget') }}</option>
                                    @foreach($versions as $version)
                                        <option value="{{ $version->id }}">{{ $version->name }} · {{ $version->fiscalYear->label() }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        @endif
                        <x-ui.field for="budget_percent" :label="__('Förändring i procent')" error="percent">
                            <input id="budget_percent" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.percent" placeholder="{{ __('Till exempel 5 eller -2,5') }}">
                        </x-ui.field>
                    </div>
                @endif
                <div class="flex flex-wrap gap-3">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Skapa budget') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        @endif

        @if($versions->isEmpty())
            <x-ui.empty-state :title="__('Ingen budget ännu')">{{ __('Skapa en budget för räkenskapsåret. Börja tomt, med förra årets utfall eller med en kopia av en annan budget.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="py-2 pe-4 font-medium">{{ __('Namn') }}</th>
                            <th class="py-2 pe-4 font-medium">{{ __('Räkenskapsår') }}</th>
                            <th class="py-2 pe-4 font-medium">{{ __('Status') }}</th>
                            <th class="py-2 pe-4 text-right font-medium">{{ __('Rader') }}</th>
                            <th class="py-2 font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($versions as $version)
                            <tr wire:key="budget-{{ $version->id }}">
                                <td class="py-3 pe-4">
                                    <a href="{{ route('accounting.budgets.show', ['budgetVersion' => $version->id]) }}" wire:navigate class="font-semibold text-ink underline-offset-2 hover:underline">{{ $version->name }}</a>
                                    @if($version->is_active)
                                        <x-ui.badge tone="accent" class="ms-2">{{ __('Används i rapporterna') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="py-3 pe-4 text-gray-700">{{ $version->fiscalYear->label() }}</td>
                                <td class="py-3 pe-4"><x-ui.badge :tone="$version->isLocked() ? 'neutral' : 'warning'">{{ $version->status->label() }}</x-ui.badge></td>
                                <td class="py-3 pe-4 text-right tabular-nums">{{ $version->lines_count }}</td>
                                <td class="py-3 text-right whitespace-nowrap">
                                    @if($canManage)
                                        @unless($version->is_active)
                                            <button type="button" wire:click="activate({{ $version->id }})" class="text-sm font-medium text-gray-700 underline">{{ __('Använd i rapporterna') }}</button>
                                        @endunless
                                        <button type="button" wire:click="delete({{ $version->id }})" wire:confirm="{{ __('Ta bort budgeten :name med alla rader? Bokföringen påverkas inte, men budgeten går inte att få tillbaka.', ['name' => $version->name]) }}" class="ms-3 text-sm font-medium text-gray-600 underline">{{ __('Ta bort') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($canManage)
        <livewire:accounting.budget-sie-import />
    @endif
</div>
