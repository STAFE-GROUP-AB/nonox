@php($select = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm')
<div class="space-y-6">
    <x-ui.card :title="__('Avtal som företaget är bundet av')" :description="__('Välj avtalet och om företaget är bundet som medlem i arbetsgivarorganisationen eller genom hängavtal med facket. Avtalets värden kommer från NONOX avtalsdata med källa, och används i lönen först när versionen är kontrollerad.')">
        @if($bindings->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Inget avtal är valt ännu. Lägg till det avtal företaget är bundet av. Anställda som inte omfattas av något avtal lämnas utan avtal.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Avtal') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Bundet som') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Bundet') }}</th>
                            <th class="py-2 font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($bindings as $binding)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-gray-900">{{ $binding->name }}</td>
                                <td class="py-2 pr-4">{{ App\Modules\Agreements\Models\AgreementRuleVersion::variantLabel((string) $binding->variant) }}</td>
                                <td class="py-2 pr-4 tabular-nums whitespace-nowrap">{{ $binding->bound_from?->toDateString() }} – {{ $binding->bound_to?->toDateString() }}</td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    @if($canManage)
                                        <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="editBinding({{ $binding->id }})">{{ __('Ändra') }}</button>
                                        <button type="button" class="ml-3 text-sm text-gray-600 underline" wire:click="removeBinding({{ $binding->id }})" wire:confirm="{{ __('Ta bort avtalet från företaget? Anställningar som är knutna till det måste flyttas först.') }}">{{ __('Ta bort') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <x-input-error for="binding" class="mt-2" />

        @if($canManage)
            @if($editingBinding === null)
                <x-button type="button" class="mt-4" wire:click="editBinding">{{ __('Lägg till avtal') }}</x-button>
            @else
                <form wire:submit="saveBinding" class="mt-4 grid gap-3 rounded-lg bg-gray-50 p-4 sm:grid-cols-2">
                    <x-ui.field for="binding_key" :label="__('Avtal')" error="agreement_key">
                        <select id="binding_key" wire:model="binding.agreement_key" class="{{ $select }}">
                            <option value="">{{ __('Välj avtal') }}</option>
                            @foreach($agreements as $version)
                                <option value="{{ $version->agreement_key }}">{{ $version->agreementRules()->name() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="binding_variant" :label="__('Bundet som')" error="variant">
                        <select id="binding_variant" wire:model="binding.variant" class="{{ $select }}">
                            @foreach(App\Modules\Agreements\Models\AgreementRuleVersion::VARIANTS as $variant)
                                <option value="{{ $variant }}">{{ App\Modules\Agreements\Models\AgreementRuleVersion::variantLabel($variant) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="binding_from" :label="__('Bundet från')" error="bound_from">
                        <input id="binding_from" type="date" wire:model="binding.bound_from" class="{{ $select }}">
                    </x-ui.field>
                    <x-ui.field for="binding_to" :label="__('Bundet till (om bindningen har upphört)')" error="bound_to">
                        <input id="binding_to" type="date" wire:model="binding.bound_to" class="{{ $select }}">
                    </x-ui.field>
                    <div class="flex items-center gap-3 sm:col-span-2">
                        <x-button type="submit">{{ __('Spara avtalet') }}</x-button>
                        <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                    </div>
                </form>
            @endif
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Anställda')" :description="__('Varje anställnings avtal, lönegrupp och branschvana, och minimilönen som gäller i :month. Åldern räknas från födelsedatumet. Den som inte omfattas av avtalet (till exempel en chef utanför avtalet) lämnas utan avtal.', ['month' => $today->translatedFormat('F Y')])">
        @if($rows === [])
            <p class="text-sm text-gray-600">{{ __('Det finns inga anställda ännu. Lägg till dem under Lön → Anställda.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Anställd') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Avtal och lönegrupp') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Branschvana från') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Minimilön') }}</th>
                            <th class="py-2 font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($rows as $row)
                            @php($employment = $row['employment'])
                            <tr>
                                <td class="py-2 pr-4 align-top">
                                    <span class="block font-medium text-gray-900">{{ $employment->employee?->first_name }} {{ $employment->employee?->last_name }}</span>
                                    <span class="block text-xs text-gray-500">{{ $employment->position_title }}</span>
                                </td>
                                <td class="py-2 pr-4 align-top">
                                    @if($row['binding'] === null)
                                        <span class="text-gray-500">{{ __('Inte omfattad av avtal') }}</span>
                                    @else
                                        <span class="block">{{ $row['binding']->name }}</span>
                                        <span class="block text-xs text-gray-500">{{ $row['group'] !== null ? $row['group']['name'] : ($employment->agreement_group ?: __('Lönegrupp saknas')) }}</span>
                                        @if(! $row['usable'])
                                            <span class="mt-1 inline-flex rounded-full bg-accent-50 px-2 py-0.5 text-xs font-medium text-accent-700">{{ __('Versionen är inte kontrollerad') }}</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="py-2 pr-4 align-top tabular-nums">{{ $employment->industry_experience_from?->toDateString() }}</td>
                                <td class="py-2 pr-4 align-top">
                                    @if($row['check'] !== null && $row['check']['minimum_ore'] !== null)
                                        <span class="block tabular-nums">{{ App\Support\Money\Money::ofOre($row['check']['minimum_ore'])->format() }} {{ $row['check']['basis'] === 'hourly_ore' ? __('per timme') : __('per månad') }}</span>
                                        <span class="block text-xs text-gray-500">{{ __(':age år', ['age' => $row['check']['age']]) }}</span>
                                    @endif
                                    @if($row['warning'] !== null)
                                        <p class="mt-1 text-xs text-accent-700">{{ $row['warning'] }}</p>
                                    @endif
                                </td>
                                <td class="py-2 align-top text-right">
                                    @if($canManage && $editingEmployment !== $employment->id)
                                        <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="editEmployment({{ $employment->id }})">{{ __('Ändra') }}</button>
                                    @endif
                                </td>
                            </tr>
                            @if($editingEmployment === $employment->id)
                                <tr>
                                    <td colspan="5" class="bg-gray-50 px-4 py-3">
                                        <form wire:submit="saveEmployment" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                            <x-ui.field for="terms_agreement" :label="__('Avtal')" error="collective_agreement_id">
                                                <select id="terms_agreement" wire:model.live="terms.collective_agreement_id" class="{{ $select }}">
                                                    <option value="">{{ __('Inte omfattad av avtal') }}</option>
                                                    @foreach($bindings as $binding)
                                                        <option value="{{ $binding->id }}">{{ $binding->name }}</option>
                                                    @endforeach
                                                </select>
                                            </x-ui.field>
                                            <x-ui.field for="terms_group" :label="__('Lönegrupp')" error="agreement_group">
                                                <select id="terms_group" wire:model="terms.agreement_group" class="{{ $select }}" @disabled($groups === [])>
                                                    <option value="">{{ __('Välj lönegrupp') }}</option>
                                                    @foreach($groups as $group)
                                                        <option value="{{ $group['code'] }}">{{ $group['name'] }}{{ $group['description'] ? ' – '.$group['description'] : '' }}</option>
                                                    @endforeach
                                                </select>
                                            </x-ui.field>
                                            <x-ui.field for="terms_experience" :label="__('Branschvana från')" :hint="__('Den dag erfarenheten i branschen räknas från, enligt avtalets regel.')" error="industry_experience_from">
                                                <input id="terms_experience" type="date" wire:model="terms.industry_experience_from" class="{{ $select }}">
                                            </x-ui.field>
                                            <x-ui.field for="terms_overtime" :label="__('Övertid')" error="overtime_compensation">
                                                <select id="terms_overtime" wire:model="terms.overtime_compensation" class="{{ $select }}">
                                                    <option value="">{{ __('Enligt avtalet') }}</option>
                                                    <option value="PAY">{{ __('Betalas ut') }}</option>
                                                    <option value="COMP_TIME">{{ __('Tas ut som komptid') }}</option>
                                                    <option value="INCLUDED_IN_SALARY">{{ __('Ingår i lönen (skriftligt avtal)') }}</option>
                                                </select>
                                            </x-ui.field>
                                            <x-ui.field for="terms_ob" :label="__('OB')" error="ob_compensation">
                                                <select id="terms_ob" wire:model="terms.ob_compensation" class="{{ $select }}">
                                                    <option value="">{{ __('Enligt avtalet') }}</option>
                                                    <option value="PAY">{{ __('Betalas ut') }}</option>
                                                    <option value="INCLUDED">{{ __('Ingår i lönen (skriftligt avtal)') }}</option>
                                                </select>
                                            </x-ui.field>
                                            <x-ui.field for="terms_fixed_supplements" :label="__('Fasta tillägg per månad')" :hint="__('Räknas in i månadslönen för avtalets sjukavdrag från dag 15. Lämna tomt om det inte finns några.')" error="fixed_supplements">
                                                <input id="terms_fixed_supplements" type="text" inputmode="decimal" wire:model="terms.fixed_supplements" class="{{ $select }}">
                                            </x-ui.field>
                                            <div class="flex items-start gap-2 sm:col-span-2">
                                                <input id="terms_direct_transfer" type="checkbox" wire:model="terms.sick_pay_direct_transfer" class="mt-1 rounded border-gray-300">
                                                <label for="terms_direct_transfer" class="text-sm text-gray-700">
                                                    {{ __('Kom direkt från en anställning med sjuklön till dag 90') }}
                                                    <span class="block text-xs text-gray-500">{{ __('Då gäller avtalets längre sjuklönetid redan första året.') }}</span>
                                                </label>
                                            </div>
                                            <div class="flex items-end gap-3">
                                                <x-button type="submit">{{ __('Spara') }}</x-button>
                                                <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
