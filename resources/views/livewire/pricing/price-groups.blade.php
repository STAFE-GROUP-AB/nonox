@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<div class="space-y-6">
    @if($showForm)
        <x-ui.card :title="$editingId ? __('Ändra kundgrupp') : __('Ny kundgrupp')">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-6">
                <x-ui.field for="group_code" :label="__('Kod')" error="code" class="sm:col-span-2">
                    <input id="group_code" type="text" class="{{ $input }} mt-1 uppercase" wire:model="form.code" maxlength="20" autocomplete="off">
                </x-ui.field>
                <x-ui.field for="group_name" :label="__('Namn')" error="name" class="sm:col-span-4">
                    <input id="group_name" type="text" class="{{ $input }} mt-1" wire:model="form.name" maxlength="100" placeholder="{{ __('Till exempel: Återförsäljare, Offentlig sektor') }}">
                </x-ui.field>
                <x-ui.field for="group_list" :label="__('Prislista för gruppen')" error="price_list_id" class="sm:col-span-6" :hint="__('Kunderna i gruppen får den här listans priser. En kund med egen prislista får sin egen i stället.')">
                    <select id="group_list" class="{{ $input }} mt-1" wire:model="form.price_list_id">
                        <option value="">{{ __('Ingen, kunderna får grundpriset') }}</option>
                        @foreach($listChoices as $choice)
                            <option value="{{ $choice->id }}">{{ $choice->code }} · {{ $choice->name }} ({{ $choice->currency }})</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <div class="flex items-center gap-4 sm:col-span-6">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            @if($canManage && ! $showForm)
                <x-button type="button" wire:click="create">{{ __('Ny kundgrupp') }}</x-button>
            @endif
            <a href="{{ route('pricing.lists') }}" class="text-sm font-medium text-accent-700 underline">{{ __('Prislistor') }}</a>
            <input type="search" class="{{ $input }} max-w-xs" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på kod eller namn') }}" aria-label="{{ __('Sök kundgrupp') }}">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="archived">
                {{ __('Visa arkiverade') }}
            </label>
        </div>

        @if($groups->isEmpty())
            <x-ui.empty-state class="mt-6" :title="$search !== '' || $archived ? __('Ingen kundgrupp matchar') : __('Inga kundgrupper ännu')">
                @if($search !== '' || $archived)
                    {{ __('Ändra sökningen eller filtret.') }}
                @else
                    {{ __('En kundgrupp samlar kunder som ska ha samma priser, till exempel återförsäljare. Du ger gruppen en prislista, lägger kunderna i gruppen på kundens flik Priser, och kan rikta en kampanj till gruppen.') }}
                @endif
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ __('Kundgrupper') }}</caption>
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Kod') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Namn') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Prislista') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Kunder') }}</th>
                            <th scope="col" class="py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($groups as $group)
                            <tr wire:key="group-{{ $group->id }}">
                                <td class="py-3 pr-4 font-semibold tabular-nums text-gray-900">{{ $group->code }}</td>
                                <td class="py-3 pr-4">{{ $group->name }}</td>
                                <td class="py-3 pr-4">
                                    @if($group->priceList)
                                        <a href="{{ route('pricing.lists.show', $group->priceList->id) }}" class="text-accent-700 hover:underline">{{ $group->priceList->code }} · {{ $group->priceList->name }}</a>
                                    @else
                                        <span class="text-gray-500">{{ __('Grundpriset') }}</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 text-right tabular-nums">{{ $group->customers_count }}</td>
                                <td class="py-3 text-right whitespace-nowrap">
                                    @if($canManage)
                                        <button type="button" class="text-sm font-medium text-accent-700 hover:underline" wire:click="edit({{ $group->id }})">{{ __('Ändra') }}</button>
                                        @if($group->archived_at)
                                            <button type="button" class="ms-3 text-sm font-medium text-gray-700 underline" wire:click="restore({{ $group->id }})">{{ __('Återställ') }}</button>
                                        @else
                                            <button type="button" class="ms-3 text-sm font-medium text-gray-700 underline" wire:click="archive({{ $group->id }})" wire:confirm="{{ __('Arkivera kundgruppen? Den kan inte väljas för nya kunder, men du kan återställa den senare.') }}">{{ __('Arkivera') }}</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $groups->links() }}</div>
        @endif
    </x-ui.card>
</div>
