@php
    use App\Domain\Group\IntercompanyKind;
    use App\Domain\Group\IntercompanySide;
    use App\Livewire\Group\IntercompanyPage;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<div class="space-y-6" data-intercompany>
    <x-ui.card :title="__('Koncernföretag bland kunder och leverantörer')"
        :description="__('En kund eller leverantör som är ett annat företag i samma koncern. Det andra företaget anges med sitt organisationsnummer.')">
        @if($counterparties->isEmpty())
            <x-ui.empty-state :title="__('Inga koncernföretag markerade ännu')">
                {{ __('Markera de kunder och leverantörer som ingår i samma koncern som företaget. Sedan kan NONOX markera raderna på deras fakturor och betalningar.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Kund eller leverantör') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Koncernföretagets organisationsnummer') }}</th>
                            <th class="py-2 pr-4"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($counterparties as $counterparty)
                            <tr wire:key="counterparty-{{ $counterparty->id }}">
                                <td class="py-2 pr-4 text-gray-900">
                                    <span class="text-xs text-gray-500">{{ $counterparty->side()->label() }}</span>
                                    {{ $counterparty->partyName() }}
                                </td>
                                <td class="py-2 pr-4 tabular-nums">{{ $counterparty->counterparty_org_number }}</td>
                                <td class="py-2 pr-4 text-right">
                                    @if($canWrite)
                                        <button type="button" class="text-sm text-gray-600 underline" wire:click="unmark({{ $counterparty->id }})" wire:confirm="{{ __('Markeringen av :name tas bort. Raderna den har gett förlorar sin markering nästa gång du uppdaterar koncernmarkeringarna. Rader du har markerat själv ändras inte.', ['name' => $counterparty->partyName()]) }}">{{ __('Ta bort markeringen') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($canWrite && $suggestions !== [])
            <div class="mt-6 rounded-2xl bg-stone-50 p-4" data-intercompany-suggestions>
                <h3 class="text-sm font-semibold text-ink">{{ __('Förslag från företagets innehav') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('Företaget äger andelar i de här bolagen, och de finns i kund- eller leverantörsregistret.') }}</p>
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach($suggestions as $suggestion)
                        <li wire:key="suggestion-{{ $suggestion['side']->value }}-{{ $suggestion['id'] }}">
                            <button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="suggest('{{ $suggestion['side']->value }}', {{ $suggestion['id'] }})">
                                {{ __(':side :name, :number', ['side' => $suggestion['side']->label(), 'name' => $suggestion['name'], 'number' => $suggestion['org']]) }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($canWrite)
            <form wire:submit="mark" class="mt-6 grid gap-4 sm:grid-cols-[10rem_1fr_12rem_auto] sm:items-start" data-intercompany-mark>
                <x-ui.field for="mark_side" :label="__('Kund eller leverantör')">
                    <select id="mark_side" class="{{ $input }} mt-1" wire:model.live="markSide">
                        @foreach(IntercompanySide::cases() as $side)
                            <option value="{{ $side->value }}">{{ $side->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="mark_search" :label="$markSide === 'supplier' ? __('Leverantör') : __('Kund')" error="markPartyId">
                    <input id="mark_search" type="text" class="{{ $input }} mt-1" wire:model.live.debounce.300ms="markSearch" autocomplete="off" placeholder="{{ __('Sök på namn, nummer eller organisationsnummer') }}">
                    @if($matches->isNotEmpty())
                        <ul class="mt-1 divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white text-sm" role="listbox">
                            @foreach($matches as $match)
                                <li wire:key="match-{{ $match->id }}">
                                    <button type="button" class="w-full px-3 py-2 text-left hover:bg-stone-50 focus:bg-stone-50 focus:outline-hidden" wire:click="choose({{ $match->id }})">
                                        <span class="tabular-nums text-gray-500">{{ $match->number }}</span> {{ $match->name }}
                                        @if($match->org_number)
                                            <span class="text-gray-500">{{ $match->org_number }}</span>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.field>
                <x-ui.field for="mark_org_number" :label="__('Organisationsnummer')" :hint="__('Förifyllt från registret.')" error="markOrgNumber">
                    <input id="mark_org_number" type="text" class="{{ $input }} mt-1 tabular-nums" wire:model="markOrgNumber" maxlength="13" autocomplete="off" placeholder="556123-4567">
                </x-ui.field>
                <div class="sm:pt-6">
                    <x-button wire:loading.attr="disabled">{{ __('Markera som koncernföretag') }}</x-button>
                </div>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Uppdatera koncernmarkeringar')"
        :description="__('Markerar raderna på fakturor, betalningar och leverantörsfakturor till och från de markerade kunderna och leverantörerna: kundfordringar och försäljning, leverantörsskulder och inköp, räntor. Moms, bank och kursdifferenser markeras inte. Rader du har markerat själv ändras inte.')">
        @if($refreshPlan === null)
            <div class="mt-4">
                <x-secondary-button wire:click="previewRefresh" wire:loading.attr="disabled">{{ __('Visa vad som ändras') }}</x-secondary-button>
            </div>
        @else
            <div class="mt-4 rounded-2xl bg-stone-50 p-4 text-sm" data-intercompany-refresh-plan>
                @if($refreshPlan['added'] + $refreshPlan['changed'] + $refreshPlan['removed'] === 0)
                    <p>{{ __('Allt är redan uppdaterat. Ingen rad behöver ändras.') }}</p>
                @else
                    <p>{{ __('Nya markeringar: :added. Ändrade: :changed. Borttagna: :removed.', $refreshPlan) }}</p>
                @endif
                @if($refreshPlan['in_closed_year'] > 0 || $refreshPlan['in_locked_period'] > 0)
                    <p class="mt-1 font-medium text-accent-700">{{ __('Av raderna som ändras ligger :closed i ett stängt räkenskapsår och :locked i en låst period. Markeringarna är inte bokföring och får ändras där, men ändringen syns i behandlingshistoriken.', ['closed' => $refreshPlan['in_closed_year'], 'locked' => $refreshPlan['in_locked_period']]) }}</p>
                @endif
                @if($refreshPlan['kept'] > 0)
                    <p class="mt-1 text-gray-600">{{ __('Rader med en markering som du har satt själv, och som lämnas som de är: :kept.', $refreshPlan) }}</p>
                @endif
                <div class="mt-3 flex flex-wrap gap-3">
                    @if($canWrite && $refreshPlan['added'] + $refreshPlan['changed'] + $refreshPlan['removed'] > 0)
                        <x-button type="button" wire:click="applyRefresh" wire:loading.attr="disabled">{{ __('Uppdatera koncernmarkeringar') }}</x-button>
                    @endif
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelRefresh">{{ __('Avbryt') }}</button>
                </div>
            </div>
        @endif
    </x-ui.card>

    @if($canWrite)
        <x-ui.card :title="__('Markera rader på ett konto')"
            :description="__('För verifikationer du bokför själv, som lån, räntor, utdelningar och koncernbidrag: alla bokförda rader på kontot under perioden får samma koncernföretag och typ. Rader som redan har en markering behåller den.')">
            <form wire:submit="previewTagging" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-start" data-intercompany-tag>
                <x-ui.field for="tag_account" :label="__('Konto')" error="account">
                    <input id="tag_account" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model.live.debounce.400ms="tagAccount" maxlength="4" autocomplete="off" placeholder="2360">
                </x-ui.field>
                <x-ui.field for="tag_from" :label="__('Från och med')" error="from">
                    <input id="tag_from" type="date" class="{{ $input }} mt-1" wire:model.live="tagFrom">
                </x-ui.field>
                <x-ui.field for="tag_to" :label="__('Till och med')" error="to">
                    <input id="tag_to" type="date" class="{{ $input }} mt-1" wire:model.live="tagTo">
                </x-ui.field>
                <x-ui.field for="tag_org_number" :label="__('Koncernföretagets organisationsnummer')" error="tagOrgNumber">
                    <input id="tag_org_number" type="text" list="intercompany-known-numbers" class="{{ $input }} mt-1 tabular-nums" wire:model.live.debounce.400ms="tagOrgNumber" maxlength="13" autocomplete="off" placeholder="556123-4567">
                    <datalist id="intercompany-known-numbers">
                        @foreach($names as $number => $name)
                            <option value="{{ $number }}">{{ $name }}</option>
                        @endforeach
                    </datalist>
                </x-ui.field>
                <x-ui.field for="tag_kind" :label="__('Vad raderna är')" error="kind">
                    <select id="tag_kind" class="{{ $input }} mt-1" wire:model.live="tagKind">
                        <option value="">{{ __('Välj') }}</option>
                        @foreach($kinds as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <div class="lg:col-span-5">
                    <x-secondary-button type="submit" wire:loading.attr="disabled">{{ __('Visa raderna') }}</x-secondary-button>
                </div>
            </form>

            @if($tagPreview !== null)
                <div class="mt-4 rounded-2xl bg-stone-50 p-4 text-sm" data-intercompany-tag-preview>
                    <p>{{ __('Bokförda rader på kontot under perioden: :rows, varav :tagged redan har en markering.', $tagPreview) }}</p>
                    @if($tagPreview['warning'] !== null)
                        <p class="mt-1 font-medium text-accent-700">{{ $tagPreview['warning'] }}</p>
                    @endif
                    @if($tagPreview['in_closed_year'] > 0 || $tagPreview['in_locked_period'] > 0)
                        <p class="mt-1 font-medium text-accent-700">{{ __('Av raderna ligger :closed i ett stängt räkenskapsår och :locked i en låst period. Markeringarna är inte bokföring och får ändras där, men ändringen syns i behandlingshistoriken.', ['closed' => $tagPreview['in_closed_year'], 'locked' => $tagPreview['in_locked_period']]) }}</p>
                    @endif
                    <p class="mt-1 tabular-nums">{{ __('Debet :debit, kredit :credit.', ['debit' => IntercompanyPage::money($tagPreview['debit_ore']), 'credit' => IntercompanyPage::money($tagPreview['credit_ore'])]) }}</p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        @if($tagPreview['rows'] > $tagPreview['tagged'])
                            <x-button type="button" wire:click="applyTagging" wire:loading.attr="disabled">{{ __('Markera raderna') }}</x-button>
                        @endif
                        @if($tagPreview['tagged'] > 0)
                            <x-secondary-button type="button" wire:click="removeTagging" wire:confirm="{{ __('Markeringen tas bort från :count rader i urvalet, även sådana du har satt själv. Verifikationerna ändras inte.', ['count' => $tagPreview['tagged']]) }}" wire:loading.attr="disabled">{{ __('Ta bort markeringarna i urvalet') }}</x-secondary-button>
                        @endif
                    </div>
                </div>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Markerade rader per koncernföretag')">
        @if($summary->isEmpty())
            <x-ui.empty-state :title="__('Inga markerade rader ännu')">
                {{ __('När raderna är markerade syns här summorna per koncernföretag och typ. De används när koncernens siffror sammanställs.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm" data-intercompany-summary>
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Koncernföretag') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Typ') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Rader') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Debet') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Kredit') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($summary as $line)
                            <tr wire:key="summary-{{ $line->org }}-{{ $line->kind }}">
                                <td class="py-2 pr-4">
                                    <button type="button" class="text-left text-accent-700 hover:underline" wire:click="$set('filterOrg', '{{ $line->org }}')">
                                        <span class="tabular-nums">{{ $line->org }}</span>
                                        @isset($names[$line->org])
                                            <span class="text-gray-600">{{ $names[$line->org] }}</span>
                                        @endisset
                                    </button>
                                </td>
                                <td class="py-2 pr-4">{{ IntercompanyKind::tryFrom($line->kind)?->label() }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $line->rows }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ IntercompanyPage::money($line->debit_ore) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ IntercompanyPage::money($line->credit_ore) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Markerade rader')">
        <div class="mt-2 flex flex-wrap gap-4">
            <x-ui.field for="filter_org" :label="__('Koncernföretag')">
                <select id="filter_org" class="{{ $input }} mt-1" wire:model.live="filterOrg">
                    <option value="">{{ __('Alla') }}</option>
                    @foreach($summary->pluck('org')->unique() as $number)
                        <option value="{{ $number }}">{{ $number }}{{ isset($names[$number]) ? ' '.$names[$number] : '' }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="filter_kind" :label="__('Typ')">
                <select id="filter_kind" class="{{ $input }} mt-1" wire:model.live="filterKind">
                    <option value="">{{ __('Alla') }}</option>
                    @foreach($kinds as $kind)
                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        </div>

        @if($tags->isEmpty())
            <p class="mt-4 text-sm text-gray-600">{{ __('Inga markerade rader i urvalet.') }}</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm" data-intercompany-tags>
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Verifikation') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Datum') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Konto') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Debet') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Kredit') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Koncernföretag') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Typ') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Markerad') }}</th>
                            <th class="py-2 pr-4"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($tags as $tag)
                            <tr wire:key="tag-{{ $tag->id }}">
                                <td class="py-2 pr-4">
                                    <a href="{{ route('accounting.vouchers.show', $tag->row->voucher) }}" class="font-medium text-accent-700 hover:underline">{{ $tag->row->voucher->reference() }}</a>
                                </td>
                                <td class="py-2 pr-4 tabular-nums">{{ $tag->row->voucher->voucher_date->toDateString() }}</td>
                                <td class="py-2 pr-4"><span class="tabular-nums">{{ $tag->row->account->number }}</span> {{ $tag->row->account->name }}</td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="$tag->row->debit_ore" :currency="false" /></td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="$tag->row->credit_ore" :currency="false" /></td>
                                <td class="py-2 pr-4 tabular-nums">{{ $tag->counterparty_org_number }}</td>
                                <td class="py-2 pr-4">
                                    @if($canWrite)
                                        <label for="tag_kind_{{ $tag->id }}" class="sr-only">{{ __('Typ') }}</label>
                                        <select id="tag_kind_{{ $tag->id }}" class="rounded-lg border-gray-300 text-sm" wire:change="changeTag({{ $tag->id }}, $event.target.value)">
                                            @foreach($kinds as $kind)
                                                <option value="{{ $kind->value }}" @selected($tag->kind === $kind)>{{ $kind->label() }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        {{ $tag->kind->label() }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-600">{{ $tag->source->label() }}</td>
                                <td class="py-2 pr-4 text-right">
                                    @if($canWrite)
                                        <button type="button" class="text-sm text-gray-600 underline" wire:click="removeTag({{ $tag->id }})" wire:confirm="{{ __('Raden förlorar sin markering, även om du har satt den själv. Verifikationen ändras inte.') }}">{{ __('Ta bort') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $tags->links() }}</div>
        @endif
    </x-ui.card>

    <p class="text-sm text-gray-500">{{ __('Markeringarna är en klassificering bredvid bokföringen, inte en del av den. Verifikationerna, numreringen och kontrollen av huvudboken påverkas inte, och varje ändring skrivs i behandlingshistoriken.') }}</p>
</div>
