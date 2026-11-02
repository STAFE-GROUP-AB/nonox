@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<div class="space-y-6">
    @if($canManage)
        <x-ui.card :title="__('Lägg till ett konto')" :description="__('Kontotypen väljs automatiskt utifrån första siffran: 1 tillgångar, 2 eget kapital och skulder, 3 intäkter, 4–8 kostnader.')">
            <form wire:submit="addAccount" class="grid gap-4 sm:grid-cols-[8rem_1fr_auto] sm:items-start">
                <x-ui.field for="new_number" :label="__('Kontonummer')" error="number">
                    <input id="new_number" type="text" inputmode="numeric" maxlength="4" class="{{ $input }} mt-1 tabular-nums" wire:model="newNumber" placeholder="6993">
                </x-ui.field>
                <x-ui.field for="new_name" :label="__('Namn')" error="name">
                    <input id="new_name" type="text" class="{{ $input }} mt-1" wire:model="newName">
                </x-ui.field>
                <div class="sm:pt-6">
                    <x-button wire:loading.attr="disabled" wire:target="addAccount">{{ __('Lägg till') }}</x-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            <label for="account_search" class="sr-only">{{ __('Sök konto') }}</label>
            <input id="account_search" type="search" class="{{ $input }} sm:max-w-xs" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på nummer eller namn') }}">

            <label for="account_class" class="sr-only">{{ __('Kontoklass') }}</label>
            <select id="account_class" class="{{ $input }} sm:max-w-xs" wire:model.live="class">
                <option value="">{{ __('Alla kontoklasser') }}</option>
                @foreach($classes as $number => $label)
                    <option value="{{ $number }}">{{ $number }} – {{ $label }}</option>
                @endforeach
            </select>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <x-checkbox wire:model.live="showInactive" />
                {{ __('Visa inaktiva konton') }}
            </label>
        </div>

        @if($accounts->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga konton hittades')">
                {{ __('Prova ett annat sökord eller en annan kontoklass.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th scope="col" class="py-2 pr-4 font-medium w-24">{{ __('Konto') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Namn') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium hidden sm:table-cell">{{ __('Typ') }}</th>
                            @if($canManage)
                                <th scope="col" class="py-2 font-medium text-right"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($accounts as $account)
                            <tr wire:key="account-{{ $account->id }}" @class(['text-gray-400' => ! $account->is_active])>
                                <td class="py-2.5 pr-4 font-semibold tabular-nums {{ $account->is_active ? 'text-gray-900' : '' }}">{{ $account->number }}</td>
                                <td class="py-2.5 pr-4">
                                    @if($editingId === $account->id)
                                        <form wire:submit="saveName" class="flex flex-wrap items-center gap-2">
                                            <label for="editing_name" class="sr-only">{{ __('Nytt namn för konto :number', ['number' => $account->number]) }}</label>
                                            <input id="editing_name" type="text" class="{{ $input }} max-w-md py-1.5" wire:model="editingName" autofocus>
                                            <x-button class="py-1.5" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                                            <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelEditing">{{ __('Avbryt') }}</button>
                                        </form>
                                        <x-input-error for="name" class="mt-1" />
                                    @else
                                        {{ $account->name }}
                                        @unless($account->is_active)
                                            <x-ui.badge class="ms-2">{{ __('Inaktivt') }}</x-ui.badge>
                                        @endunless
                                        @if($account->sru_code === \App\Domain\AnnualReport\K2Schedule::GOODS_FOR_RESALE_SRU && $account->number >= \App\Domain\AnnualReport\K2Schedule::PURCHASES[0] && $account->number <= \App\Domain\AnnualReport\K2Schedule::PURCHASES[1])
                                            <x-ui.badge class="ms-2">{{ __('Handelsvaror') }}</x-ui.badge>
                                        @endif
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4 hidden sm:table-cell text-gray-600">{{ $account->type->label() }}</td>
                                @if($canManage)
                                    <td class="py-2.5 text-right whitespace-nowrap">
                                        @if($editingId !== $account->id)
                                            <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="startEditing({{ $account->id }})">{{ __('Byt namn') }}</button>
                                            <span class="text-gray-300 mx-1" aria-hidden="true">·</span>
                                            <button type="button" class="text-sm font-medium text-gray-600 hover:text-gray-900" wire:click="toggleActive({{ $account->id }})">
                                                {{ $account->is_active ? __('Inaktivera') : __('Aktivera') }}
                                            </button>
                                            @if($account->number >= \App\Domain\AnnualReport\K2Schedule::PURCHASES[0] && $account->number <= \App\Domain\AnnualReport\K2Schedule::PURCHASES[1])
                                                <span class="text-gray-300 mx-1" aria-hidden="true">·</span>
                                                <button type="button" class="text-sm font-medium text-gray-600 hover:text-gray-900" wire:click="toggleGoodsForResale({{ $account->id }})">
                                                    {{ $account->sru_code === \App\Domain\AnnualReport\K2Schedule::GOODS_FOR_RESALE_SRU ? __('Räkna som råvaror') : __('Räkna som handelsvaror') }}
                                                </button>
                                            @endif
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6">{{ $accounts->links() }}</div>
        @endif
    </x-ui.card>
</div>
