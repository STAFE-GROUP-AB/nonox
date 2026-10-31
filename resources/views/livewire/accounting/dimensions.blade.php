@php
    use App\Domain\Accounting\Dimension;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
@endphp

<div class="space-y-6">
    @if($canManage)
        <x-ui.card :title="$kind === Dimension::CostCenter ? __('Lägg till kostnadsställe') : __('Lägg till projekt')">
            <form wire:submit="add" class="grid gap-4 sm:grid-cols-[10rem_1fr_auto] sm:items-start">
                <x-ui.field for="new_code" :label="__('Kod')" :hint="__('Kort, till exempel 10 eller MALMO.')" error="code">
                    <input id="new_code" type="text" class="{{ $input }} mt-1" wire:model="newCode" maxlength="20" autocomplete="off">
                </x-ui.field>
                <x-ui.field for="new_name" :label="__('Namn')" error="name">
                    <input id="new_name" type="text" class="{{ $input }} mt-1" wire:model="newName" maxlength="255" placeholder="{{ $kind === Dimension::CostCenter ? __('Till exempel: Restaurang Malmö') : __('Till exempel: Ombyggnad kök') }}">
                </x-ui.field>
                <div class="sm:pt-6">
                    <x-button wire:loading.attr="disabled">{{ __('Lägg till') }}</x-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($objects->isEmpty())
            <x-ui.empty-state :title="$kind === Dimension::CostCenter ? __('Inga kostnadsställen ännu') : __('Inga projekt ännu')">
                {{ $kind === Dimension::CostCenter
                    ? __('Med kostnadsställen ser du vad varje restaurang, butik eller avdelning kostar och drar in. Behöver du inte det kan du hoppa över det här.')
                    : __('Med projekt följer du intäkter och kostnader för ett uppdrag eller en satsning. Behöver du inte det kan du hoppa över det här.') }}
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($objects as $object)
                    <li wire:key="object-{{ $object->id }}" class="flex flex-wrap items-center gap-4 py-3">
                        <span class="w-24 flex-none font-semibold tabular-nums text-gray-900">{{ $object->code }}</span>
                        @if($editingId === $object->id)
                            <form wire:submit="saveName" class="flex flex-1 flex-wrap items-center gap-3">
                                <input type="text" class="{{ $input }} max-w-sm" wire:model="editingName" maxlength="255" aria-label="{{ __('Namn') }}">
                                <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                                <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelEditing">{{ __('Avbryt') }}</button>
                                <x-input-error for="name" class="w-full" />
                            </form>
                        @else
                            <span @class(['flex-1', 'text-gray-900' => $object->is_active, 'text-gray-400' => ! $object->is_active])>{{ $object->name }}</span>
                            @unless($object->is_active)
                                <x-ui.badge>{{ __('Inaktivt') }}</x-ui.badge>
                            @endunless
                            @if($fiscalYear !== null)
                                @php($result = $results->get($object->id, ['income' => 0, 'costs' => 0]))
                                <span class="flex gap-6 text-sm tabular-nums" title="{{ __('Räkenskapsåret :year', ['year' => $fiscalYear->label()]) }}">
                                    <span><span class="block text-xs text-gray-500">{{ __('Intäkter') }}</span>{{ App\Support\Money\Money::ofOre($result['income'])->format() }}</span>
                                    <span><span class="block text-xs text-gray-500">{{ __('Kostnader') }}</span>{{ App\Support\Money\Money::ofOre($result['costs'])->format() }}</span>
                                    <span class="font-semibold text-ink"><span class="block text-xs font-normal text-gray-500">{{ __('Resultat') }}</span>{{ App\Support\Money\Money::ofOre($result['income'] - $result['costs'])->format() }}</span>
                                </span>
                            @endif
                            @if($canManage)
                                <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="startEditing({{ $object->id }})">{{ __('Byt namn') }}</button>
                                <button type="button" class="text-sm text-gray-600 underline" wire:click="toggleActive({{ $object->id }})">{{ $object->is_active ? __('Inaktivera') : __('Aktivera') }}</button>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    @if($fiscalYear !== null && $objects->isNotEmpty())
        <p class="text-sm text-gray-500">{{ __('Intäkter, kostnader och resultat gäller räkenskapsåret :year och bygger på bokförda rader med :dimension. Rapporterna kan filtreras på samma sätt.', ['year' => $fiscalYear->label(), 'dimension' => mb_strtolower($kind->label())]) }}</p>
    @endif
    <p class="text-sm text-gray-500">{{ __('Du väljer kostnadsställe och projekt per rad när du bokför. Koden ändras aldrig, eftersom gamla verifikationer pekar på den; ett namn kan du byta när du vill.') }}</p>
</div>
