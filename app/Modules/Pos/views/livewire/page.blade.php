@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $secondary = 'inline-flex min-h-11 items-center rounded-xl px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-300 hover:ring-stone-400 disabled:opacity-50';
@endphp
<div class="space-y-6">
    <x-ui.card :title="__('Kassor')" :description="__('Välj kassan som företaget använder. Du godkänner kopplingen hos kassaleverantören och kan koppla bort den när som helst.')">
        <div class="mt-2 grid gap-4 lg:grid-cols-2">
            @foreach($providers as $provider)
                @php($list = $connections->get($provider->value, collect()))
                @php($live = $list->first(fn ($connection) => $connection->status !== $disconnected))
                <div wire:key="provider-{{ $provider->value }}" class="rounded-2xl p-4 ring-1 ring-stone-200">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold text-ink">{{ $provider->label() }}
                                @if(! $provider->isConfigured())
                                    <x-ui.badge>{{ __('Inte konfigurerad på den här servern') }}</x-ui.badge>
                                @elseif($live !== null)
                                    <x-ui.badge :tone="$live->status->value === 'ACTIVE' ? 'success' : 'accent'">{{ $live->status->label() }}</x-ui.badge>
                                @endif
                            </p>
                            <p class="mt-1 text-sm text-gray-600">{{ $provider->description() }}</p>
                        </div>
                        @if($canManage && $provider->isConfigured() && ($live === null || $live->status->value !== 'ACTIVE'))
                            <x-button type="button" wire:click="connect('{{ $provider->value }}')">{{ $live === null ? __('Koppla') : __('Koppla igen') }}</x-button>
                        @elseif($canManage && ! $provider->isConfigured())
                            <x-button type="button" disabled>{{ __('Koppla') }}</x-button>
                        @endif
                    </div>

                    @if($live !== null)
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <dt class="text-gray-500">{{ __('Senast synkad') }}</dt>
                            <dd>{{ $live->last_synced_at?->setTimezone('Europe/Stockholm')->format('Y-m-d H:i') ?? __('Inte ännu') }}</dd>
                            <dt class="text-gray-500">{{ __('Artiklar') }}</dt>
                            <dd>{{ $live->pushesProducts() ? ($live->syncDirection() === 'TWO_WAY' ? __('Båda hållen: kassans produkter kopplas till artiklar, ändringar skickas till kassan') : __('Från NONOX till kassan')) : __('Skickas inte') }}</dd>
                            <dt class="text-gray-500">{{ __('Lagersaldo till kassan') }}</dt>
                            <dd>{{ $live->pushesStock() ? __('Ja, som ändringar') : __('Nej') }}</dd>
                            <dt class="text-gray-500">{{ __('Försäljningen dras från') }}</dt>
                            <dd>{{ $live->location?->full_code ?? __('Artikelns standardlagerställe') }}</dd>
                        </dl>
                        @if($live->last_error !== null)
                            <p class="mt-2 text-sm text-accent-700">{{ __('Senaste kontakten med kassan misslyckades. NONOX försöker igen.') }}</p>
                        @endif

                        @if($canManage)
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if($live->status->value === 'ACTIVE')
                                    <button type="button" class="{{ $secondary }}" wire:click="fetchSales({{ $live->id }})" wire:loading.attr="disabled">{{ __('Hämta försäljning nu') }}</button>
                                    <button type="button" class="{{ $secondary }}" wire:click="syncProducts({{ $live->id }})">{{ __('Skicka artiklar nu') }}</button>
                                @endif
                                <button type="button" class="{{ $secondary }}" wire:click="edit({{ $live->id }})">{{ __('Inställningar') }}</button>
                                <button type="button" class="{{ $secondary }}" wire:click="askDisconnect({{ $live->id }})">{{ __('Koppla bort') }}</button>
                            </div>
                        @endif

                        @if($confirmingDisconnect === $live->id)
                            <div class="mt-4 rounded-2xl bg-accent-50 p-4 ring-1 ring-accent-200" role="alertdialog" aria-labelledby="disconnect-{{ $live->id }}">
                                <p id="disconnect-{{ $live->id }}" class="text-sm text-gray-800">{{ __('Kopplingen till :till tas bort och nycklarna raderas här. Ingen mer försäljning kommer in och inget skickas till kassan. Det som redan kommit in, kopplingarna till artiklar och loggen finns kvar.', ['till' => $provider->label()]) }}</p>
                                <div class="mt-3 flex gap-2">
                                    <x-button type="button" wire:click="disconnect">{{ __('Koppla bort') }}</x-button>
                                    <button type="button" class="{{ $secondary }}" wire:click="$set('confirmingDisconnect', null)">{{ __('Avbryt') }}</button>
                                </div>
                            </div>
                        @endif

                        @if($editing === $live->id)
                            <form wire:submit="saveSettings" class="mt-4 space-y-4 rounded-2xl bg-paper p-4 ring-1 ring-stone-200">
                                <x-ui.field for="pos-direction-{{ $live->id }}" :label="__('Artiklar')" error="form.sync_direction" :hint="__('Båda hållen kopplar först kassans befintliga produkter till artiklar med samma EAN, streckkod, SKU eller artikelnummer, så att inget läggs upp två gånger.')">
                                    <select id="pos-direction-{{ $live->id }}" wire:model="form.sync_direction" class="{{ $input }} mt-1">
                                        <option value="ONE_WAY">{{ __('Från NONOX till kassan') }}</option>
                                        <option value="TWO_WAY">{{ __('Båda hållen') }}</option>
                                    </select>
                                </x-ui.field>
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" wire:model="form.push_products" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500">
                                    {{ __('Skicka nya och ändrade artiklar till kassan') }}
                                </label>
                                @if($provider->supportsStock())
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" wire:model="form.push_stock" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500">
                                        {{ __('Skicka lagerändringar till kassan') }}
                                    </label>
                                @endif
                                <x-ui.field for="pos-location-{{ $live->id }}" :label="__('Lagerställe för kassan')" error="form.location_id" :hint="__('Försäljningen dras härifrån, och bara ändringar här skickas till kassan. Tomt: artikelns standardlagerställe.')">
                                    <select id="pos-location-{{ $live->id }}" wire:model="form.location_id" class="{{ $input }} mt-1">
                                        <option value="">{{ __('Artikelns standardlagerställe') }}</option>
                                        @foreach($locations as $location)
                                            <option value="{{ $location->id }}">{{ $location->full_code }}{{ $location->name ? ' '.$location->name : '' }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                                @if($provider->value === 'onslip')
                                    <div class="space-y-2">
                                        <p class="text-sm font-medium text-gray-700">{{ __('Varugrupp per momssats') }}</p>
                                        <p class="text-sm text-gray-500">{{ __('Onslip har momsen på varugruppen. En artikel skickas till varugruppen för dess momssats.') }}</p>
                                        <button type="button" class="{{ $secondary }}" wire:click="loadProductGroups">{{ __('Hämta varugrupper från Onslip') }}</button>
                                        @foreach([2500, 1200, 600, 0] as $rate)
                                            <x-ui.field :for="'pos-group-'.$rate" :label="__(':rate % moms', ['rate' => intdiv($rate, 100)])" :error="'form.product_groups.'.$rate">
                                                @if($productGroups !== [])
                                                    <select id="pos-group-{{ $rate }}" wire:model="form.product_groups.{{ $rate }}" class="{{ $input }} mt-1">
                                                        <option value="">{{ __('Skickas inte') }}</option>
                                                        @foreach($productGroups as $group)
                                                            <option value="{{ $group['id'] }}">{{ $group['name'] }}{{ $group['vat_rate'] !== null ? ' ('.intdiv($group['vat_rate'], 100).' %)' : '' }}</option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <input id="pos-group-{{ $rate }}" type="number" inputmode="numeric" wire:model="form.product_groups.{{ $rate }}" class="{{ $input }} mt-1" placeholder="{{ __('Varugruppens id') }}">
                                                @endif
                                            </x-ui.field>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="flex gap-2">
                                    <x-button>{{ __('Spara') }}</x-button>
                                    <button type="button" class="{{ $secondary }}" wire:click="$set('editing', null)">{{ __('Avbryt') }}</button>
                                </div>
                            </form>
                        @endif

                        @if($logs->has($live->id))
                            <details class="mt-4">
                                <summary class="cursor-pointer text-sm font-semibold text-accent-700">{{ __('Logg') }}</summary>
                                <ul class="mt-2 space-y-1 text-sm">
                                    @foreach($logs->get($live->id) as $entry)
                                        <li wire:key="log-{{ $entry->id }}" @class(['text-accent-700' => $entry->level !== 'info', 'text-gray-700' => $entry->level === 'info'])>
                                            <span class="tabular-nums text-gray-500">{{ $entry->created_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i') }}</span> {{ $entry->message }}
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Omatchade rader')" :description="__('Rader från kassan som inte kunde kopplas till en lagerförd artikel, eller där lagret inte räckte. Skriv artikelnumret, EAN eller SKU. Kassans produkt kopplas till artikeln, och raden dras från lagret.')">
        @if($unmatched->isEmpty())
            <x-ui.empty-state class="mt-4" :title="__('Inga omatchade rader')">{{ __('Allt som sålts i kassan har dragits från lagret.') }}</x-ui.empty-state>
        @else
            <div class="-mx-4 mt-4 overflow-x-auto sm:mx-0">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-xs font-medium uppercase tracking-[0.12em] text-gray-500">
                            <th class="px-3 py-2">{{ __('Datum') }}</th>
                            <th class="px-3 py-2">{{ __('Vara i kassan') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Antal') }}</th>
                            <th class="px-3 py-2">{{ __('Artikel') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($unmatched as $row)
                            <tr wire:key="row-{{ $row->id }}" class="border-b border-stone-100 align-top">
                                <td class="px-3 py-2 tabular-nums">{{ $row->sale->business_date->toDateString() }}</td>
                                <td class="px-3 py-2">{{ $row->name }}
                                    @if($row->barcode || $row->sku)
                                        <span class="block text-xs text-gray-500">{{ collect([$row->barcode, $row->sku])->filter()->implode(' · ') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ \App\Modules\Inventory\Domain\Quantity::format($row->quantity_milli) }}</td>
                                <td class="px-3 py-2">
                                    @if($canLink)
                                        <form wire:submit="linkRow({{ $row->id }})" class="flex flex-wrap items-start gap-2">
                                            <label for="pos-article-{{ $row->id }}" class="sr-only">{{ __('Artikelnummer, EAN eller SKU') }}</label>
                                            <input id="pos-article-{{ $row->id }}" type="text" wire:model="articleFor.{{ $row->id }}" class="{{ $input }} w-44" placeholder="{{ __('Artikelnummer eller EAN') }}" autocomplete="off">
                                            <x-button>{{ __('Koppla') }}</x-button>
                                            <button type="button" class="{{ $secondary }}" wire:click="ignoreRow({{ $row->id }})">{{ __('Undanta') }}</button>
                                        </form>
                                        <x-input-error :for="'articleFor.'.$row->id" class="mt-1" />
                                    @else
                                        <span class="text-gray-500">{{ __('Väntar på att någon med lagerbehörighet kopplar raden') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $unmatched->links() }}</div>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Dagar att bokföra')" :description="__('Försäljning från kassan som ännu inte finns i en verifikation. Utkastet hamnar i serie K och bokförs inte förrän du gör det. Bifoga kassans dagsrapport (Z-rapport) som underlag.')">
        @if($days->isEmpty())
            <x-ui.empty-state class="mt-4" :title="__('Inget att bokföra')">{{ __('All försäljning från kassan finns i en verifikation.') }}</x-ui.empty-state>
        @else
            <div class="-mx-4 mt-4 overflow-x-auto sm:mx-0">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-xs font-medium uppercase tracking-[0.12em] text-gray-500">
                            <th class="px-3 py-2">{{ __('Dag') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Försäljningar') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Summa') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($days as $day)
                            @php($date = \Carbon\CarbonImmutable::parse($day->business_date)->toDateString())
                            <tr wire:key="day-{{ $date }}" class="border-b border-stone-100">
                                <td class="px-3 py-2 tabular-nums">{{ $date }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $day->sales }}</td>
                                <td class="px-3 py-2 text-right"><x-ui.amount :money="\App\Support\Money\Money::ofOre((int) $day->total_ore)" /></td>
                                <td class="px-3 py-2 text-right">
                                    @if($canDraft)
                                        <button type="button" class="{{ $secondary }}" wire:click="createDraft('{{ $date }}')" wire:loading.attr="disabled">{{ __('Skapa verifikationsutkast') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
