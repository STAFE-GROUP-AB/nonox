@php
    use App\Domain\TaxAccount\TaxAccountMatcher;
    use App\Support\Money\Money;

    $kronor = fn (?int $amount): Money => Money::ofOre((int) $amount * 100);
    $time = fn (?string $value): string => $value === null ? '–' : rescue(fn () => \Carbon\CarbonImmutable::parse($value)->timezone(config('app.timezone'))->format('Y-m-d H:i'), '–', false);
@endphp

<div class="space-y-6">
    <x-ui.card :title="__('Skattekontot hos Skatteverket')">
        <x-slot:action><x-ui.badge tone="accent">{{ __('Beta') }}</x-ui.badge></x-slot:action>

        @if($test)
            <p class="mb-4 rounded-lg bg-sky-50 px-4 py-2 text-sm text-sky-900">{{ __('Testmiljö: NONOX är kopplat till Skatteverkets testtjänst. Uppgifterna är Skatteverkets testdata.') }}</p>
        @endif

        <div class="space-y-2 text-sm text-gray-700">
            <p>{{ __('NONOX hämtar saldot, transaktionerna och utbetalningsspärrarna på företagets skattekonto hos Skatteverket när du klickar på Hämta från Skatteverket, och ställer dem bredvid konto 1630 i bokföringen. Uppgifterna sparas inte i NONOX. Bara när du bokför en transaktion sparas Skatteverkets id för den, datumet och beloppet, så att den aldrig bokförs två gånger.') }}</p>
            <p>{{ __('Uppgifterna får bara hämtas för bokföring, redovisning och uppföljning, och bara av den som företräder företaget eller har fått företagets uppdrag att göra det. Skatteverket kontrollerar din behörighet vid varje hämtning.') }}</p>
            <p>{{ __('Skatteverket loggar alla anrop från NONOX (auditloggning) och sparar loggarna i upp till 5 år. Skatteverket använder dem för att förebygga och utreda säkerhetsincidenter.') }}</p>
        </div>

        @if(! $signedIn)
            <div class="mt-5">
                <a href="{{ route('skatteverket.signIn', ['tillbaka' => $back, 'tjanst' => 'skattekonto']) }}" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg bg-accent-600 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Logga in hos Skatteverket med e-legitimation') }}</a>
                <p class="mt-2 text-xs text-gray-500">{{ __('Du loggar in hos Skatteverket, inte i NONOX, med e-legitimation, till exempel BankID. Inloggningen gäller i ungefär en timme. Gäller din inloggning bara moms eller arbetsgivardeklaration behöver du logga in igen.') }}</p>
            </div>
        @else
            <form wire:submit="fetch" class="mt-5 flex flex-wrap items-end gap-4">
                <div>
                    <label for="tax-account-date" class="block text-sm font-medium text-gray-700">{{ __('Avstämningsdag') }}</label>
                    <input id="tax-account-date" type="date" wire:model="date" class="mt-1 block border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm">
                </div>
                <div>
                    <label for="tax-account-from" class="block text-sm font-medium text-gray-700">{{ __('Transaktioner från och med') }}</label>
                    <input id="tax-account-from" type="date" wire:model="from" class="mt-1 block border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm">
                </div>
                <x-button type="submit" wire:loading.attr="disabled">{{ __('Hämta från Skatteverket') }}</x-button>
                <button type="button" wire:click="signOut" class="text-sm text-gray-500 underline">{{ __('Logga ut från Skatteverket') }}</button>
            </form>
            <p class="mt-2 text-xs text-gray-500">{{ __('Är avstämningsdagen i dag visar Skatteverket saldot när skattekontot senast uppdaterades, och NONOX jämför med 1630 den dagen. Skatteverket visar transaktioner högst 915 dagar bakåt.') }}</p>
        @endif
    </x-ui.card>

    @if($result)
        @php($balance = $result['balance'])
        <x-ui.card :title="__('Saldo :date', ['date' => $result['date']])">
            <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-sm text-gray-600">{{ __('Saldo hos Skatteverket') }}</dt>
                    <dd class="mt-1 text-xl font-semibold"><x-ui.amount :money="$kronor($balance['skatteverket'])" /></dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-600">{{ __('Saldo på 1630 i NONOX') }}</dt>
                    <dd class="mt-1 text-xl font-semibold">
                        @if($result['ledger_ore'] === null)
                            {{ __('Inget räkenskapsår för dagen') }}
                        @else
                            <x-ui.amount :money="Money::ofOre($result['ledger_ore'])" />
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-600">{{ __('Skillnad') }}</dt>
                    <dd class="mt-1 text-xl font-semibold">
                        @if($result['difference_ore'] === null)
                            –
                        @else
                            <x-ui.amount :money="Money::ofOre($result['difference_ore'])" />
                        @endif
                    </dd>
                </div>
            </dl>

            @if($result['difference_ore'] !== null)
                <p @class(['mt-4 text-sm', 'text-green-800' => $result['difference_ore'] === 0, 'text-accent-800' => $result['difference_ore'] !== 0])>
                    {{ $result['difference_ore'] === 0
                        ? __('Konto 1630 stämmer med skattekontot hos Skatteverket.')
                        : __('Konto 1630 stämmer inte med skattekontot. Titta på transaktionerna som saknas i bokföringen och på det som bara finns i NONOX nedan.') }}
                </p>
            @endif

            <dl class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Preliminär ränta hos Skatteverket') }}</dt><dd><x-ui.amount :money="$kronor($balance['interestSkatteverket'])" /></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Saldo hos Kronofogden') }}</dt><dd><x-ui.amount :money="$kronor($balance['kronofogden'])" /></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Preliminär ränta hos Kronofogden') }}</dt><dd><x-ui.amount :money="$kronor($balance['interestKronofogden'])" /></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('OCR-nummer för inbetalning') }}</dt><dd class="font-mono">{{ $balance['ocr'] !== '' ? $balance['ocr'] : '–' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Nästa avstämningsdag') }}</dt><dd>{{ $balance['nextReconciliation'] ?? '–' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Skattekontot uppdaterades') }}</dt><dd>{{ $time($balance['updatedAt']) }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-gray-500">{{ __('Den preliminära räntan är inte bokförd på skattekontot än och ingår inte i saldot. Saldot på 1630 jämförs med Skatteverkets del av skattekontot; en skuld hos Kronofogden visas för sig.') }}</p>

            @if($result['information'] !== [])
                <div class="mt-4 rounded-lg bg-stone-50 p-4 text-sm text-gray-700">
                    <p class="font-semibold">{{ __('Information från Skatteverket') }}</p>
                    <ul class="mt-1 list-disc ps-5">
                        @foreach($result['information'] as $text)
                            <li>{{ $text }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Transaktioner hos Skatteverket')" :description="__('Från :from till :to. En transaktion räknas som bokförd när en verifikation ändrar 1630 med samma belopp samma dag, eller när dagens transaktioner tillsammans är en verifikation.', ['from' => $result['from'], 'to' => $result['date']])">
            @if($result['transactions'] === [])
                <p class="text-sm text-gray-600">{{ __('Skatteverket visar inga transaktioner för perioden.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 text-left text-gray-600">
                                <th class="py-2 pe-4 font-medium">{{ __('Datum') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('Text') }}</th>
                                <th class="py-2 pe-4 text-right font-medium">{{ __('Belopp') }}</th>
                                <th class="py-2 font-medium">{{ __('I bokföringen') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($result['transactions'] as $row)
                                <tr class="border-b border-stone-100 align-top" wire:key="tax-transaction-{{ $row['id'] }}">
                                    <td class="py-2 pe-4 whitespace-nowrap">{{ $row['date'] }}</td>
                                    <td class="py-2 pe-4">{{ $row['text'] }}</td>
                                    <td class="py-2 pe-4 text-right">
                                        @if($row['skatteverket'] !== null)<x-ui.amount :money="$kronor($row['skatteverket'])" />@endif
                                        @if($row['kronofogden'] !== null)
                                            <div class="text-xs text-gray-600">{{ __('Kronofogden') }} <x-ui.amount :money="$kronor($row['kronofogden'])" /></div>
                                        @endif
                                    </td>
                                    <td class="py-2">
                                        @if($row['previous'] !== null)
                                            <p class="mb-1 text-xs text-gray-600">{{ __('Bokfördes tidigare som :reference, som sedan har rättats.', ['reference' => $row['previous']]) }}</p>
                                        @endif
                                        @switch($row['status'])
                                            @case(TaxAccountMatcher::BOOKED)
                                                {{ __('Bokförd från skattekontot (:reference)', ['reference' => $row['reference']]) }}
                                                @break
                                            @case(TaxAccountMatcher::MATCHED)
                                                {{ __('Bokförd (:reference)', ['reference' => $row['reference']]) }}
                                                @break
                                            @case(TaxAccountMatcher::MATCHED_SUM)
                                                {{ __('Bokförd tillsammans med dagens andra transaktioner (:reference)', ['reference' => $row['reference']]) }}
                                                @break
                                            @case(TaxAccountMatcher::MANUAL)
                                                <span class="text-accent-800">{{ __('Bokförs för hand') }}</span>
                                                @break
                                            @default
                                                <span class="font-medium text-accent-800">{{ __('Saknas i bokföringen') }}</span>
                                                @if($canBook)
                                                    <div x-data="{ confirming: false }" class="mt-2 flex flex-wrap items-center gap-2">
                                                        <label for="tax-account-{{ $row['id'] }}" class="sr-only">{{ __('Motkonto') }}</label>
                                                        <select id="tax-account-{{ $row['id'] }}" wire:model="accounts.{{ $row['id'] }}" class="block max-w-64 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm">
                                                            <option value="">{{ __('Välj motkonto') }}</option>
                                                            @foreach($accountOptions as $option)
                                                                <option value="{{ $option['number'] }}">{{ $option['number'] }} {{ $option['name'] }}</option>
                                                            @endforeach
                                                        </select>
                                                        <button type="button" x-show="! confirming" x-on:click="confirming = true" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Bokför') }}</button>
                                                        <div x-show="confirming" x-cloak class="flex flex-wrap items-center gap-2">
                                                            <span class="text-xs text-gray-700">{{ __('En verifikation i serie A med 1630 mot kontot du valt, daterad :date. Den kan inte ändras efteråt, bara rättas.', ['date' => $row['date']]) }}</span>
                                                            <x-button type="button" wire:click="book({{ $row['id'] }})" wire:loading.attr="disabled">{{ __('Ja, bokför') }}</x-button>
                                                            <button type="button" class="text-sm text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                                                        </div>
                                                    </div>
                                                    @if($row['proposal'] !== null)
                                                        <p class="mt-1 text-xs text-gray-500">{{ __('NONOX föreslår konto :account utifrån Skatteverkets text. Kontrollera det.', ['account' => $row['proposal']]) }}</p>
                                                    @elseif($row['typical'] !== null)
                                                        <p class="mt-1 text-xs text-gray-500">{{ __('I BAS bokförs det här vanligen på konto :account, som inte finns i kontoplanen. Välj konto själv.', ['account' => $row['typical']]) }}</p>
                                                    @endif
                                                @endif
                                        @endswitch
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-gray-500">{{ __('Kontrollera att en transaktion som saknas inte redan är bokförd en annan dag innan du bokför den, till exempel en arbetsgivardeklaration som bokfördes på löndagen. Spara skattekontoutdraget som underlag.') }}</p>
            @endif
        </x-ui.card>

        @if($result['unmatched'] !== [])
            <x-ui.card :title="__('Bokfört på 1630 men inte på skattekontot')" :description="__('Verifikationer under perioden som NONOX inte hittar en transaktion med samma belopp och dag för hos Skatteverket.')">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 text-left text-gray-600">
                                <th class="py-2 pe-4 font-medium">{{ __('Datum') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('Verifikation') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('Belopp på 1630') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($result['unmatched'] as $entry)
                                <tr class="border-b border-stone-100" wire:key="tax-entry-{{ $entry['voucher_id'] }}">
                                    <td class="py-2 pe-4">{{ $entry['date'] }}</td>
                                    <td class="py-2 pe-4"><a href="{{ route('accounting.vouchers.show', $entry['voucher_id']) }}" class="text-accent-600 hover:text-accent-700">{{ $entry['reference'] }}</a></td>
                                    <td class="py-2 text-right"><x-ui.amount :money="Money::ofOre($entry['amount_ore'])" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card :title="__('Kommande transaktioner')" :description="__('Beslutade skatter och avgifter som Skatteverket bokför på förfallodagen. De har inte påverkat saldot eller räntan än och bokförs inte i NONOX nu.')">
            @if($result['coming'] === [])
                <p class="text-sm text-gray-600">{{ __('Skatteverket visar inga kommande transaktioner.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 text-left text-gray-600">
                                <th class="py-2 pe-4 font-medium">{{ __('Förfallodag') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('Text') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('Belopp') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($result['coming'] as $index => $row)
                                <tr class="border-b border-stone-100" wire:key="tax-coming-{{ $index }}">
                                    <td class="py-2 pe-4">{{ $row['dueDate'] ?? $row['date'] }}</td>
                                    <td class="py-2 pe-4">{{ $row['text'] }}</td>
                                    <td class="py-2 text-right">
                                        @if($row['skatteverket'] !== null)<x-ui.amount :money="$kronor($row['skatteverket'])" />@endif
                                        @if($row['kronofogden'] !== null)<div class="text-xs text-gray-600">{{ __('Kronofogden') }} <x-ui.amount :money="$kronor($row['kronofogden'])" /></div>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Utbetalningsspärrar')" :description="__('Spärrar som företaget själv har begärt, så att pengar står kvar på skattekontot och täcker kommande skatter och avgifter.')">
            @if($result['blocks'] === [])
                <p class="text-sm text-gray-600">{{ __('Företaget har ingen utbetalningsspärr.') }}</p>
            @else
                <ul class="space-y-1 text-sm">
                    @foreach($result['blocks'] as $block)
                        <li wire:key="tax-block-{{ $block['id'] }}">
                            {{ $block['total']
                                ? __('Hela saldot är spärrat från :from', ['from' => $block['from']])
                                : __(':amount är spärrat från :from', ['amount' => $kronor($block['amount'])->format(), 'from' => $block['from']]) }}{{ $block['until'] !== null ? ' '.__('till och med :date', ['date' => $block['until']]) : '' }}.
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif
</div>
