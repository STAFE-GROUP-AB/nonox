@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $kind = $form['kind'] ?? 'PERCENT';
    $articles = $form['articles'] ?? 'all';
@endphp

<div class="space-y-6">
    @if($canManage)
        <x-ui.card :title="__('Ny prisuppdatering')">
            <form wire:submit="plan" class="grid gap-4 sm:grid-cols-6">
                <x-ui.field for="update_kind" :label="__('Hur priserna ändras')" error="kind" class="sm:col-span-3">
                    <select id="update_kind" class="{{ $input }} mt-1" wire:model.live="form.kind">
                        @foreach($kinds as $each)
                            <option value="{{ $each->value }}">{{ $each->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="update_date" :label="__('Gäller från')" error="effective_date" class="sm:col-span-3" :hint="__('I dag eller senare. Ett datum längre fram förbereder ändringen; den börjar gälla den dagen.')">
                    <input id="update_date" type="date" class="{{ $input }} mt-1" wire:model="form.effective_date" min="{{ now()->toDateString() }}">
                </x-ui.field>

                @if($kind === 'FILE')
                    <x-ui.field for="update_file_target" :label="__('Filen gäller')" error="targets" class="sm:col-span-3">
                        <select id="update_file_target" class="{{ $input }} mt-1" wire:model="form.file_target">
                            <option value="base">{{ __('Grundpriserna i artikelregistret') }}</option>
                            @foreach($lists as $list)
                                <option value="{{ $list->id }}">{{ $list->code }} · {{ $list->name }} ({{ $list->currency }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="update_file" :label="__('Fil med priser')" error="file" class="sm:col-span-3" :hint="__('CSV eller Excel (.xlsx) med kolumnerna Artikelnummer och Pris eller Rabatt %. Enhet, Från antal och Gäller från är valfria. Högst 12 MB. Exportera en prislista för att få en mall.')">
                        <input id="update_file" type="file" accept=".csv,.txt,.xlsx" class="mt-1 block w-full text-sm text-gray-700" wire:model="file">
                        <div wire:loading wire:target="file" class="mt-1 text-sm text-gray-500">{{ __('Laddar upp filen …') }}</div>
                    </x-ui.field>
                @else
                    <fieldset class="sm:col-span-6">
                        <legend class="text-sm font-medium text-gray-700">{{ __('Vilka priser') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                            <label class="flex items-center gap-2 text-sm text-gray-800">
                                <input type="checkbox" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="form.base">
                                {{ __('Grundpriserna i artikelregistret') }}
                            </label>
                            @foreach($lists as $list)
                                <label class="flex items-center gap-2 text-sm text-gray-800" wire:key="target-{{ $list->id }}">
                                    <input type="checkbox" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="form.list_ids" value="{{ $list->id }}">
                                    {{ $list->code }} · {{ $list->name }} <span class="text-gray-500">({{ $list->currency }})</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-1 text-sm text-gray-500">{{ __('En lista som följer grundpriset med en procent följer med av sig själv; bara priser du har lagt in i listan ändras här.') }}</p>
                        <x-input-error for="targets" field="targets" class="mt-2" />
                        <x-input-error for="list_ids" field="list_ids" class="mt-2" />
                    </fieldset>

                    <x-ui.field for="update_articles" :label="__('Vilka artiklar')" error="articles" class="sm:col-span-2">
                        <select id="update_articles" class="{{ $input }} mt-1" wire:model.live="form.articles">
                            <option value="all">{{ __('Alla aktiva artiklar') }}</option>
                            <option value="range">{{ __('Ett nummerintervall') }}</option>
                            <option value="search">{{ __('Nummer eller benämning innehåller') }}</option>
                            <option value="selected">{{ __('Valda artiklar') }}</option>
                        </select>
                    </x-ui.field>

                    @if($articles === 'range')
                        <x-ui.field for="update_from" :label="__('Från artikelnummer')" error="number_from" class="sm:col-span-2">
                            <input id="update_from" type="text" class="{{ $input }} mt-1" wire:model="form.number_from" maxlength="50" autocomplete="off">
                        </x-ui.field>
                        <x-ui.field for="update_to" :label="__('Till och med artikelnummer')" error="number_to" class="sm:col-span-2">
                            <input id="update_to" type="text" class="{{ $input }} mt-1" wire:model="form.number_to" maxlength="50" autocomplete="off">
                        </x-ui.field>
                    @elseif($articles === 'search')
                        <x-ui.field for="update_search" :label="__('Innehåller')" error="search" class="sm:col-span-4">
                            <input id="update_search" type="text" class="{{ $input }} mt-1" wire:model="form.search" maxlength="100" autocomplete="off">
                        </x-ui.field>
                    @elseif($articles === 'selected')
                        <div class="sm:col-span-4">
                            <label for="update_article_search" class="block text-sm font-medium text-gray-700">{{ __('Sök artikel') }}</label>
                            <input id="update_article_search" type="search" class="{{ $input }} mt-1" wire:model.live.debounce.300ms="articleSearch" autocomplete="off" placeholder="{{ __('Nummer eller benämning') }}">
                            @if($articleMatches->isNotEmpty())
                                <ul class="mt-1 divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white">
                                    @foreach($articleMatches as $match)
                                        <li wire:key="match-{{ $match->id }}">
                                            <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50" wire:click="chooseArticle({{ $match->id }})">
                                                <span class="tabular-nums font-medium">{{ $match->number }}</span> {{ $match->name }}
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($chosenArticles as $chosen)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-800" wire:key="chosen-{{ $chosen->id }}">
                                        {{ $chosen->number }} {{ $chosen->name }}
                                        <button type="button" class="ms-1 text-gray-500 hover:text-gray-800" wire:click="removeArticle({{ $chosen->id }})" aria-label="{{ __('Ta bort :article ur urvalet', ['article' => $chosen->number]) }}">×</button>
                                    </span>
                                @endforeach
                            </div>
                            <x-input-error for="article_ids" field="article_ids" class="mt-2" />
                        </div>
                    @else
                        <div class="sm:col-span-4"></div>
                    @endif

                    <x-ui.field for="update_supplier" :label="__('Leverantör')" error="supplier_ids" class="sm:col-span-3" :hint="__('Bara artiklar vars standardleverantör är den valda.')">
                        <select id="update_supplier" class="{{ $input }} mt-1" wire:model="form.supplier_id">
                            <option value="">{{ __('Alla leverantörer') }}</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="update_price_group" :label="__('Kundgrupp')" error="price_group_ids" class="sm:col-span-3" :hint="__('Begränsar uppdateringen till kundgruppens prislista och kampanjer riktade till den. Gäller inte grundpriserna.')">
                        <select id="update_price_group" class="{{ $input }} mt-1" wire:model="form.price_group_id">
                            <option value="">{{ __('Alla kundgrupper') }}</option>
                            @foreach($priceGroups as $group)
                                <option value="{{ $group->id }}">{{ $group->code }} · {{ $group->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    @if($kind === 'PERCENT')
                        <x-ui.field for="update_percent" :label="__('Ändring i procent')" error="percent" class="sm:col-span-2" :hint="__('4 höjer med 4 %, -10 sänker med 10 %.')">
                            <input id="update_percent" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.percent" autocomplete="off">
                        </x-ui.field>
                    @elseif($kind === 'MARGIN')
                        <x-ui.field for="update_margin" :label="__('Önskad täckningsgrad i procent')" error="margin" class="sm:col-span-2" :hint="__('30 ger ett pris där 30 % av priset exklusive moms blir kvar efter inköpspriset. Samma beräkning som lägsta täckningsgrad. Artiklar utan inköpspris hoppas över och visas i förhandsgranskningen.')">
                            <input id="update_margin" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.margin" autocomplete="off">
                        </x-ui.field>
                    @elseif($kind === 'AMOUNT')
                        <x-ui.field for="update_amount" :label="__('Ändring i kronor')" error="amount" class="sm:col-span-2" :hint="__('10 höjer med 10 kr, -5,50 sänker. Ändrar bara priser i kronor; i en lista med priser inklusive moms läggs beloppet till priset inklusive moms.')">
                            <input id="update_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.amount" autocomplete="off">
                        </x-ui.field>
                    @else
                        <x-ui.field for="update_index_old" :label="__('Gammalt indextal')" error="index_old" class="sm:col-span-1">
                            <input id="update_index_old" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.index_old" autocomplete="off">
                        </x-ui.field>
                        <x-ui.field for="update_index_new" :label="__('Nytt indextal')" error="index_new" class="sm:col-span-1">
                            <input id="update_index_new" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.index_new" autocomplete="off">
                        </x-ui.field>
                    @endif

                    <x-ui.field for="update_rounding" :label="__('Avrundning')" error="rounding" class="sm:col-span-2">
                        <select id="update_rounding" class="{{ $input }} mt-1" wire:model="form.rounding">
                            @foreach($roundings as $each)
                                <option value="{{ $each->value }}">{{ $each->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    @if($kind === 'INDEX')
                        <p class="sm:col-span-6 text-sm text-gray-500">{{ __('Priserna räknas om med kvoten mellan det nya och det gamla indextalet. Skriv in talen från det index ert avtal hänvisar till; NONOX hämtar inga indextal.') }}</p>
                    @endif
                @endif

                <div class="sm:col-span-6 flex items-center gap-3">
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="plan,file">{{ __('Förhandsgranska') }}</x-button>
                    <span class="text-sm text-gray-500">{{ __('Inget ändras förrän du har sett de nya priserna och tryckt på Genomför.') }}</span>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Tidigare prisuppdateringar')">
        @if($runs->isEmpty())
            <x-ui.empty-state :title="__('Inga prisuppdateringar ännu')">
                {{ __('En prisuppdatering ändrar många priser på en gång och sparas här, så du kan se vad som ändrades och när.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ __('Tidigare prisuppdateringar') }}</caption>
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Nr') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Ändring') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Gäller från') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Priser') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Gjord av') }}</th>
                            <th scope="col" class="py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($runs as $run)
                            <tr wire:key="run-{{ $run->id }}">
                                <td class="py-3 pr-4 tabular-nums font-semibold text-gray-900">{{ $run->id }}</td>
                                <td class="py-3 pr-4">{{ $run->kind->label() }}</td>
                                <td class="py-3 pr-4 tabular-nums">{{ $run->effective_date->toDateString() }}</td>
                                <td class="py-3 pr-4">
                                    <x-ui.badge :tone="match ($run->status) { App\Domain\Pricing\PriceUpdateStatus::Applied => 'success', App\Domain\Pricing\PriceUpdateStatus::Scheduled => 'accent', App\Domain\Pricing\PriceUpdateStatus::Reverted => 'neutral', default => 'warning' }">{{ $run->status->label() }}</x-ui.badge>
                                </td>
                                <td class="py-3 pr-4 text-right tabular-nums">{{ $run->counts['applied'] ?? ($run->counts['ready'] ?? 0) }}</td>
                                <td class="py-3 pr-4 text-gray-700">{{ $run->creator?->name }}<span class="block text-xs text-gray-500 tabular-nums">{{ $run->created_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i') }}</span></td>
                                <td class="py-3 text-right">
                                    <a href="{{ route('pricing.updates.show', $run->id) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Visa') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $runs->links() }}</div>
        @endif
    </x-ui.card>
</div>
