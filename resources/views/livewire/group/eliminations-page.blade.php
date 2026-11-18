@php
    use App\Domain\Group\EliminationType;
    use App\Domain\Group\GroupAccessLevel;
    use App\Domain\Group\GroupEntryKind;
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $button = 'rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-hidden focus:ring-2 focus:ring-accent-500 focus:ring-offset-2 disabled:opacity-50';
    $quiet = 'text-sm font-medium text-accent-600 hover:text-accent-700 focus:outline-hidden focus:underline';
    $m = fn (int $ore): Money => Money::ofOre($ore);
    $th = 'py-2 pl-4 text-right font-medium';
    $td = 'py-2 pl-4 text-right';
    $name = fn (int $id): string => (string) ($names[$id] ?? __('Företag som inte längre finns'));
@endphp

<div class="space-y-6">
    {{-- ADR-060 2.4 and S2-R14: every consolidated screen says what it is. --}}
    <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="note">
        <p class="font-semibold">{{ __('Intern koncernrapport, inte en koncernredovisning enligt ÅRL') }}</p>
        <p class="mt-1">{{ __('Elimineringarna förs i koncernens egen journal och gäller konsolideringen av exakt samma period. De bokförs aldrig i något av företagen.') }}</p>
    </div>

    @if($groups->isEmpty())
        <x-ui.card>
            <x-ui.empty-state :title="__('Du ser ingen koncern')">
                {{ __('En koncern visas när du får se rapporter i alla dess företag, eller när du har fått rollen att läsa koncernens sammanställning. Koncerner skapas under Koncernstruktur.') }}
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <x-ui.card>
            <form wire:submit="$refresh" class="flex flex-wrap items-end gap-4">
                <x-ui.field for="eliminations_group" :label="__('Koncern')" class="min-w-56">
                    <select id="eliminations_group" class="{{ $input }} mt-1" wire:change="selectGroup($event.target.value)">
                        @foreach($groups as $option)
                            <option value="{{ $option->id }}" @selected($current && $option->id === $current->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="eliminations_from" :label="__('Från och med')">
                    <input id="eliminations_from" type="date" class="{{ $input }} mt-1" wire:model="from" required>
                </x-ui.field>
                <x-ui.field for="eliminations_to" :label="__('Till och med')">
                    <input id="eliminations_to" type="date" class="{{ $input }} mt-1" wire:model="to" required>
                </x-ui.field>
                <button type="submit" class="{{ $button }}">{{ __('Visa period') }}</button>
            </form>
            <p class="mt-3 text-xs text-gray-500">{{ __('Förslagen bygger på koncernmarkeringarna i företagens bokförda verifikationer och, för andelar och eget kapital, på balanserna. En godkänd post gäller konsolideringar av exakt samma period.') }}</p>
        </x-ui.card>

        @if($current && $level !== GroupAccessLevel::Full)
            <x-ui.card>
                <x-ui.empty-state :title="__('Elimineringarna visas inte för dig')">
                    {{ __('Förslag och koncernjournal visar enskilda företags belopp och visas bara för den som får se rapporter i alla koncernens företag. Summorna per slag finns under Konsolidering.') }}
                </x-ui.empty-state>
            </x-ui.card>
        @elseif($current && $error)
            <x-ui.card>
                <p class="text-sm text-red-700" role="alert">{{ $error }}</p>
            </x-ui.card>
        @elseif($current)
            <x-ui.card>
                <h2 class="text-lg font-semibold text-gray-900">{{ __('Förslag') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('Ett förslag kan godkännas när båda sidor är lika stora. En differens visas med båda sidorna; rätta koncernmarkeringarna eller bokföringen, eller gör en manuell post.') }}</p>

                @if($suggestions === [])
                    <p class="mt-4 text-sm text-gray-700">{{ __('Inga koncerninterna belopp hittades för perioden. Markera raderna under Bokföring → Koncernmarkeringar i varje företag.') }}</p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <caption class="sr-only">{{ __('Förslag på elimineringar') }}</caption>
                            <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Slag') }}</th>
                                <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Företag') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Ena sidan') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Andra sidan') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Differens') }}</th>
                                <th scope="col" class="py-2 pl-4 text-left font-medium">{{ __('Status') }}</th>
                                <th scope="col" class="py-2 text-right font-medium"><span class="sr-only">{{ __('Åtgärd') }}</span></th>
                            </tr></thead>
                            <tbody>
                                @foreach($suggestions as $suggestion)
                                    @php($shares = $suggestion['type'] === EliminationType::SharesEquity)
                                    <tr class="border-b border-gray-100 align-top" wire:key="suggestion-{{ $suggestion['key'] }}">
                                        <td class="py-2 pr-4">{{ $suggestion['type']->label() }}</td>
                                        <td class="py-2 pr-4">
                                            @if($shares)
                                                {{ __(':holder äger :held', ['holder' => $name($suggestion['debit_company']), 'held' => implode(', ', array_map($name, array_keys($suggestion['credit']['companies'] ?? [])))]) }}
                                            @else
                                                {{ $name($suggestion['credit_company']) }} → {{ $name($suggestion['debit_company']) }}
                                            @endif
                                            <details class="mt-1 text-xs text-gray-600">
                                                <summary class="cursor-pointer">{{ __('Konton') }}</summary>
                                                <p class="mt-1">{{ $shares ? __('Aktiekapital i dotterföretagen') : $suggestion['type']->creditSide()?->label() }}:
                                                    @foreach($suggestion['credit']['accounts'] as $account => $amount)
                                                        <span class="tabular-nums">{{ $account }}</span> {{ $m($amount)->format() }}@if(! $loop->last), @endif
                                                    @endforeach
                                                </p>
                                                <p>{{ $shares ? __('Andelar i koncernföretag hos ägaren') : $suggestion['type']->debitSide()?->label() }}:
                                                    @foreach($suggestion['debit']['accounts'] as $account => $amount)
                                                        <span class="tabular-nums">{{ $account }}</span> {{ $m($amount)->format() }}@if(! $loop->last), @endif
                                                    @endforeach
                                                </p>
                                            </details>
                                        </td>
                                        <td class="{{ $td }}"><x-ui.amount :money="$m($suggestion['credit']['total'])" /></td>
                                        <td class="{{ $td }}"><x-ui.amount :money="$m($suggestion['debit']['total'])" /></td>
                                        <td class="{{ $td }}"><x-ui.amount :money="$m($suggestion['difference'])" /></td>
                                        <td class="py-2 pl-4">
                                            @if($suggestion['accepted_entry'] !== null)
                                                <x-ui.badge tone="success">{{ __('Godkänd, post :number', ['number' => $suggestion['accepted_entry']]) }}</x-ui.badge>
                                                @if($suggestion['changed_since_accepted'])
                                                    <p class="mt-1 text-xs text-amber-800">{{ __('Beloppen har ändrats sedan förslaget godkändes. Återför posten och godkänn förslaget igen.') }}</p>
                                                @endif
                                            @elseif($suggestion['status'] === $matched)
                                                <x-ui.badge tone="accent">{{ __('Matchar') }}</x-ui.badge>
                                            @elseif($suggestion['status'] === $needsAnalysis)
                                                <x-ui.badge tone="warning">{{ __('Kräver förvärvsanalys (steg 2)') }}</x-ui.badge>
                                                <p class="mt-1 text-xs text-gray-600">{{ match (true) {
                                                    ! ($suggestion['wholly_owned'] ?? true) => __('Ägaren äger inte hela kapitalet.'),
                                                    ! ($suggestion['founded'] ?? true) => __('Ägaren äger mer än ett företag i koncernen, eller har köpt företaget i stället för att bilda det.'),
                                                    default => __('Andelarna och aktiekapitalet är inte lika stora.'),
                                                } }}</p>
                                            @elseif($suggestion['status'] === $needsReview)
                                                <x-ui.badge tone="warning">{{ __('Kräver granskning: ingående balans utan koncernmarkering') }}</x-ui.badge>
                                            @elseif($suggestion['status'] === $nothing)
                                                <x-ui.badge>{{ __('Inget att eliminera') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge tone="danger">{{ __('Differens') }}</x-ui.badge>
                                            @endif
                                        </td>
                                        <td class="py-2 text-right">
                                            @if($administers && $suggestion['status'] === $matched && $suggestion['accepted_entry'] === null)
                                                <span x-data="{ sure: false }">
                                                    <button type="button" class="{{ $quiet }}" x-show="! sure" x-on:click="sure = true">{{ __('Godkänn') }}</button>
                                                    <span x-show="sure" x-cloak class="inline-flex flex-wrap items-center justify-end gap-2">
                                                        <span class="text-xs text-gray-700">{{ __('Posten förs in i koncernjournalen och kan bara rättas med en återföring.') }}</span>
                                                        <button type="button" class="text-sm font-medium text-accent-700 hover:underline" wire:click="accept('{{ $suggestion['key'] }}')" wire:loading.attr="disabled">{{ __('Ja, godkänn') }}</button>
                                                        <button type="button" class="{{ $quiet }}" x-on:click="sure = false">{{ __('Avbryt') }}</button>
                                                    </span>
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <p class="mt-3 text-xs text-gray-600">{{ __('Fordringar, skulder och lån (konto 1000–2999) föreslås med sina utgående balanser på periodens sista dag, summerade över alla markerade verifikationer, även tidigare år. Intäkter och kostnader föreslås för perioden. Ingående balanser har inga koncernmarkeringar: har ett konto i ett förslag en ingående balans som inte kommer från bokförda verifikationer i NONOX står det Kräver granskning, och du för in elimineringen som en manuell post. Internvinster, goodwill och minoritetsandelar beräknas inte i den interna rapporten.') }}</p>
            </x-ui.card>

            @if($administers)
                <x-ui.card>
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Ny manuell post') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Ange koncernkonto, företaget som raden gäller och belopp i debet eller kredit. Posten måste balansera och gäller perioden ovan.') }}</p>
                    <form wire:submit="saveManual" class="mt-4 space-y-4">
                        <div class="flex flex-wrap items-end gap-4">
                            <x-ui.field for="manual_type" :label="__('Slag')">
                                <select id="manual_type" class="{{ $input }} mt-1" wire:model="manualType">
                                    @foreach($types as $type)
                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                            <x-ui.field for="manual_reason" :label="__('Motivering')" class="min-w-72 grow">
                                <input id="manual_reason" aria-describedby="manual_reason_help" type="text" maxlength="255" class="{{ $input }} mt-1" wire:model="manualReason" required>
                                <p id="manual_reason_help" class="mt-1 text-xs text-gray-500">{{ __('Skriv inte personuppgifter i motiveringen.') }}</p>
                            </x-ui.field>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <caption class="sr-only">{{ __('Rader i den manuella posten') }}</caption>
                                <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Företag') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Koncernkonto') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Debet') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Kredit') }}</th>
                                    <th scope="col" class="py-2 text-right font-medium"><span class="sr-only">{{ __('Åtgärd') }}</span></th>
                                </tr></thead>
                                <tbody>
                                    @foreach($manualRows as $index => $row)
                                        <tr class="border-b border-gray-100" wire:key="manual-row-{{ $index }}">
                                            <td class="py-1.5 pr-4">
                                                <label for="manual_company_{{ $index }}" class="sr-only">{{ __('Företag på rad :row', ['row' => $index + 1]) }}</label>
                                                <select id="manual_company_{{ $index }}" class="{{ $input }}" wire:model="manualRows.{{ $index }}.company">
                                                    <option value="">{{ __('Välj företag') }}</option>
                                                    @foreach($companies as $companyId)
                                                        <option value="{{ $companyId }}">{{ $name($companyId) }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="py-1.5 pr-4">
                                                <label for="manual_account_{{ $index }}" class="sr-only">{{ __('Koncernkonto på rad :row', ['row' => $index + 1]) }}</label>
                                                <input id="manual_account_{{ $index }}" type="text" inputmode="numeric" maxlength="4" class="{{ $input }} w-24" wire:model="manualRows.{{ $index }}.account" placeholder="2081">
                                            </td>
                                            <td class="py-1.5 pr-4">
                                                <label for="manual_debit_{{ $index }}" class="sr-only">{{ __('Debet på rad :row', ['row' => $index + 1]) }}</label>
                                                <input id="manual_debit_{{ $index }}" type="text" inputmode="decimal" class="{{ $input }} w-32 text-right tabular-nums" wire:model="manualRows.{{ $index }}.debit">
                                            </td>
                                            <td class="py-1.5 pr-4">
                                                <label for="manual_credit_{{ $index }}" class="sr-only">{{ __('Kredit på rad :row', ['row' => $index + 1]) }}</label>
                                                <input id="manual_credit_{{ $index }}" type="text" inputmode="decimal" class="{{ $input }} w-32 text-right tabular-nums" wire:model="manualRows.{{ $index }}.credit">
                                            </td>
                                            <td class="py-1.5 text-right">
                                                @if(count($manualRows) > 2)
                                                    <button type="button" class="text-sm font-medium text-red-700 hover:underline" wire:click="removeRow({{ $index }})" aria-label="{{ __('Ta bort rad :row', ['row' => $index + 1]) }}">{{ __('Ta bort') }}</button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="flex flex-wrap items-center gap-4">
                            <button type="button" class="{{ $quiet }}" wire:click="addRow">{{ __('Lägg till rad') }}</button>
                            <button type="submit" class="{{ $button }}" wire:loading.attr="disabled" wire:target="saveManual">{{ __('Spara posten') }}</button>
                        </div>
                    </form>
                </x-ui.card>
            @else
                <p class="text-sm text-gray-600">{{ __('Bara den som är ägare eller administratör i alla företag i koncernen kan göra poster i koncernjournalen.') }}</p>
            @endif

            <x-ui.card>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Koncernjournal för perioden') }}</h2>
                    <button type="button" class="{{ $quiet }}" wire:click="verifyLedger">{{ __('Kontrollera koncernjournalen') }}</button>
                </div>
                <p class="mt-1 text-sm text-gray-600">{{ __('Posterna numreras i en följd per koncern och kan inte ändras eller tas bort. Ett fel rättas med en återföring, som också blir en post.') }}</p>

                @if($entries->isEmpty())
                    <p class="mt-4 text-sm text-gray-700">{{ __('Inga poster för perioden ännu.') }}</p>
                @else
                    <ul class="mt-4 space-y-4">
                        @foreach($entries as $entry)
                            @if($entry->rows->contains(fn ($row) => ! ($visible[$row->company_id] ?? false)))
                                <li class="rounded-lg border border-gray-200 p-4 text-sm text-gray-700" wire:key="entry-{{ $entry->id }}">{{ __('Post :number gäller ett företag som du inte har åtkomst till.', ['number' => $entry->number]) }}</li>
                                @continue
                            @endif
                            <li class="rounded-lg border border-gray-200 p-4" wire:key="entry-{{ $entry->id }}">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="font-medium text-gray-900">{{ __('Post :number', ['number' => $entry->number]) }} · {{ $entry->kind->label() }} · {{ $entry->type->label() }}</p>
                                        <p class="text-xs text-gray-600">{{ $entry->origin->label() }} · {{ $entry->entry_date->toDateString() }} · {{ $entry->acceptor?->name ?? __('Okänd') }} · {{ $entry->accepted_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</p>
                                        @if($entry->reason)
                                            <p class="mt-1 text-sm text-gray-700">{{ $entry->reason }}</p>
                                        @endif
                                        @if($entry->reverses_id)
                                            <p class="mt-1 text-xs text-gray-600">{{ __('Återför post :number.', ['number' => $entries->firstWhere('id', $entry->reverses_id)?->number ?? '?']) }}</p>
                                        @endif
                                        @if($entry->reversedBy)
                                            <p class="mt-1 text-xs text-gray-600">{{ __('Återförd med post :number.', ['number' => $entry->reversedBy->number]) }}</p>
                                        @endif
                                    </div>
                                    @if($administers && $entry->kind !== GroupEntryKind::Reversal && ! $entry->reversedBy)
                                        @if($reversing === $entry->id)
                                            <form wire:submit="reverse" class="flex flex-wrap items-end gap-2">
                                                <x-ui.field for="reverse_reason" :label="__('Varför återförs posten?')">
                                                    <input id="reverse_reason" aria-describedby="reverse_reason_help" type="text" maxlength="255" class="{{ $input }} mt-1" wire:model="reverseReason" required>
                                                    <p id="reverse_reason_help" class="mt-1 text-xs text-gray-500">{{ __('Skriv inte personuppgifter i motiveringen.') }}</p>
                                                </x-ui.field>
                                                <button type="submit" class="rounded-lg bg-red-700 px-3 py-2 text-sm font-medium text-white hover:bg-red-800">{{ __('Återför posten') }}</button>
                                                <button type="button" class="{{ $quiet }}" wire:click="cancelReverse">{{ __('Avbryt') }}</button>
                                            </form>
                                        @else
                                            <button type="button" class="{{ $quiet }}" wire:click="startReverse({{ $entry->id }})">{{ __('Återför') }}</button>
                                        @endif
                                    @endif
                                </div>
                                <div class="mt-3 overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <caption class="sr-only">{{ __('Rader i post :number', ['number' => $entry->number]) }}</caption>
                                        <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                            <th scope="col" class="py-1.5 pr-4 text-left font-medium">{{ __('Företag') }}</th>
                                            <th scope="col" class="py-1.5 pr-4 text-left font-medium">{{ __('Företagets konto') }}</th>
                                            <th scope="col" class="py-1.5 pr-4 text-left font-medium">{{ __('Koncernkonto') }}</th>
                                            <th scope="col" class="{{ $th }}">{{ __('Debet') }}</th>
                                            <th scope="col" class="{{ $th }}">{{ __('Kredit') }}</th>
                                        </tr></thead>
                                        <tbody>
                                            @foreach($entry->rows as $row)
                                                <tr class="border-b border-gray-100">
                                                    <td class="py-1.5 pr-4">{{ $name($row->company_id) }}</td>
                                                    <td class="py-1.5 pr-4 tabular-nums">{{ $row->account_number ?? '–' }}</td>
                                                    <td class="py-1.5 pr-4 tabular-nums">{{ $row->group_account_number }}</td>
                                                    <td class="{{ $td }} py-1.5">@if($row->debit_ore > 0)<x-ui.amount :money="$m($row->debit_ore)" />@endif</td>
                                                    <td class="{{ $td }} py-1.5">@if($row->credit_ore > 0)<x-ui.amount :money="$m($row->credit_ore)" />@endif</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endif
    @endif
</div>
