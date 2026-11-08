@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($money = fn (int $ore) => App\Support\Money\Money::ofOre($ore))
@php($qty = fn (int $milli) => App\Modules\Inventory\Domain\Quantity::format($milli))
<div class="space-y-6">
    @if($canRegister)
        <x-ui.card :title="__('Registrera måltid')" :description="$payrollOn ? __('Personalmat registreras per anställd och blir kostförmån i lönen när löneadministratören har godkänt den. Måltider som säljs till personalpris i kassan registreras inte här.') : __('Utan Lön registreras bara antal måltider, utan namn. Måltider som säljs till personalpris i kassan registreras inte här.')">
            <form wire:submit="register" class="grid gap-5 sm:grid-cols-6">
                <x-ui.field for="meal-date" :label="__('Datum')" error="form.date" class="sm:col-span-2">
                    <input id="meal-date" type="date" wire:model="form.date" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="meal-type" :label="__('Måltid')" error="form.meal" class="sm:col-span-2">
                    <select id="meal-type" wire:model="form.meal" class="{{ $input }}">
                        @foreach($meals as $meal)
                            <option value="{{ $meal->value }}">{{ $meal->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="meal-reason" :label="__('Typ')" error="form.reason" class="sm:col-span-2">
                    <select id="meal-reason" wire:model.live="form.reason" class="{{ $input }}">
                        @foreach($reasons as $reason)
                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="meal-dish" :label="__('Rätt')" error="form.article_id" class="sm:col-span-3" :hint="$average === null ? __('Valfri rätt har ingen kostnad förrän ett snittpris är satt under Inställningar.') : null">
                    <select id="meal-dish" wire:model="form.article_id" class="{{ $input }}">
                        <option value="">{{ __('Valfri rätt') }}</option>
                        @foreach($dishes as $dish)
                            <option value="{{ $dish->id }}">{{ $dish->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="meal-quantity" :label="__('Antal')" error="form.quantity" class="sm:col-span-1">
                    <input id="meal-quantity" wire:model="form.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                </x-ui.field>
                @if($payrollOn && ($form['reason'] ?? '') === 'PERSONALMAT')
                    <x-ui.field for="meal-employee" :label="__('Anställd')" error="form.employee_id" class="sm:col-span-2">
                        <select id="meal-employee" wire:model="form.employee_id" class="{{ $input }}">
                            <option value="">{{ __('Välj anställd') }}</option>
                            @foreach($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="meal-paid" :label="__('Betalar per måltid')" error="form.paid_per_meal" class="sm:col-span-2" :hint="__('Dras på lönen. Lämna tomt för fri måltid.')">
                        <input id="meal-paid" wire:model="form.paid_per_meal" inputmode="decimal" class="{{ $input }} text-right tabular-nums" placeholder="0,00">
                    </x-ui.field>
                @endif
                <div class="sm:col-span-6">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Registrera måltiden') }}</x-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Underlag för månaden')" :description="__('Fria och subventionerade måltider, vad personalen har betalat och råvarukostnaden. Råvarukostnaden är inget momsunderlag: momsen på fri personalmat räknas på mer än råvarorna, och NONOX bokar ingen moms på personalmat.')">
        <label class="mb-4 block max-w-xs">
            <span class="text-sm font-medium text-gray-700">{{ __('Månad') }}</span>
            <input type="month" wire:model.live="period" class="{{ $input }}">
        </label>
        @if($summary->isEmpty())
            <x-ui.empty-state :title="__('Inga måltider den här månaden')">{{ __('Registrera personalmat ovan, så sammanställs underlaget här.') }}</x-ui.empty-state>
        @else
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-stone-200 text-left text-gray-500">
                        <th class="py-2 pr-4 font-medium">{{ __('Typ') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Måltider') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Varav fria') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Betalt av personalen') }}</th>
                        @if($seesCosts)<th class="py-2 pr-4 text-right font-medium">{{ __('Råvarukostnad') }}</th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($reasons as $reason)
                        @if($row = $summary->get($reason->value))
                            <tr>
                                <td class="py-2 pr-4">{{ $reason->label() }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $qty((int) $row->milli) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $qty((int) $row->free_milli) }}</td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money((int) $row->paid_ore)" /></td>
                                @if($seesCosts)<td class="py-2 pr-4 text-right"><x-ui.amount :money="$money((int) $row->raw_ore)" /></td>@endif
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>

    @if($canPayroll)
        <x-ui.card :title="__('Kostförmåner till lönen')" :description="__('Personalmaten för :period blir föreslagna rader per anställd. De läggs till i lönen först när du godkänner dem i månadens lönekörning. Det anställda betalar räknas mot varje måltids förmånsvärde och aldrig över det, så att andra förmåner inte minskas.', ['period' => $period])">
            <x-button type="button" wire:click="propose" wire:confirm="{{ __('Föreslå kostförmånerna för :period? Rader som redan är föreslagna ersätts; godkända rader ändras inte.', ['period' => $period]) }}" wire:loading.attr="disabled">{{ __('Skicka kostförmåner till lönen') }}</x-button>
            <x-input-error for="payroll.period" class="mt-2" />

            @if($proposals->isNotEmpty())
                <table class="mt-4 min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Anställd') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Löneart') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Antal eller belopp') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($proposals as $proposal)
                            <tr wire:key="proposal-{{ $proposal->id }}">
                                <td class="py-2 pr-4">{{ $proposal->employee?->first_name }} {{ $proposal->employee?->last_name }}
                                    @if($proposal->details['changed_after_approval'] ?? false)
                                        <span class="block text-xs font-semibold text-accent-700">{{ __('Ändrad efter godkännande') }}</span>
                                        @foreach($proposal->details['not_sent'] ?? [] as $reduction)
                                            <span class="block text-xs text-accent-700">{{ __('Minskning som inte kan skickas, rätta i nästa lönekörning: :code :value', ['code' => $reduction['pay_code'], 'value' => $reduction['amount_ore'] !== null ? $money((int) $reduction['amount_ore'])->format() : (string) $reduction['quantity']]) }}</span>
                                        @endforeach
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $proposal->pay_code }}@if($proposal->pay_code === 'FORMAN_EGENBETALNING' && ($proposal->details['excess_ore'] ?? 0) > 0)<span class="block text-xs text-gray-500">{{ __('Betalt över förmånsvärdet, skickas inte: :amount', ['amount' => $money((int) $proposal->details['excess_ore'])->format()]) }}</span>@endif</td>
                                <td class="py-2 pr-4 text-right tabular-nums">@if($proposal->amount_ore !== null)<x-ui.amount :money="$money($proposal->amount_ore)" />@else{{ str_replace('.', ',', rtrim(rtrim((string) $proposal->quantity, '0'), '.')) }}@endif</td>
                                <td class="py-2 pr-4">{{ ['PROPOSED' => __('Föreslagen'), 'APPROVED' => __('Godkänd'), 'REJECTED' => __('Avvisad'), 'REPLACED' => __('Ersatt')][$proposal->status] ?? $proposal->status }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if($proposals->contains('status', 'PROPOSED'))
                    <div class="mt-4 flex flex-wrap items-end gap-4">
                        <x-ui.field for="payroll-run" :label="__('Lönekörning')" error="payroll.run">
                            <select id="payroll-run" wire:model="runId" class="{{ $input }}">
                                <option value="">{{ __('Välj lönekörning') }}</option>
                                @foreach($runs as $run)
                                    <option value="{{ $run->id }}">{{ $run->period }} · {{ $run->payment_date?->toDateString() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-button type="button" wire:click="approve" wire:confirm="{{ __('Godkänn raderna och lägg till dem i lönekörningen? Lönekörningen behöver räknas om.') }}">{{ __('Godkänn i lönekörningen') }}</x-button>
                        <button type="button" wire:click="reject" wire:confirm="{{ __('Avvisa de föreslagna raderna? De skickas inte till lönen.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Avvisa') }}</button>
                    </div>
                    @if($runs->isEmpty())
                        <p class="mt-2 text-sm text-gray-600">{{ __('Det finns ingen lönekörning för :period. Skapa den under Lön först.', ['period' => $period]) }}</p>
                    @endif
                @endif
            @endif
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Loggen')" :description="__('Loggen kan inte ändras. En felaktig rad rättas med en ny rad.')">
        @if($entries->isEmpty())
            <x-ui.empty-state :title="__('Inga måltider registrerade')">{{ __('Registrera personalmat, representation och ägarens måltider här.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Datum') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Måltid') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Rätt') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Antal') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Typ') }}</th>
                            <th class="py-2 pr-4"><span class="sr-only">{{ __('Åtgärd') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($entries as $entry)
                            <tr wire:key="meal-{{ $entry->id }}">
                                <td class="py-2 pr-4 tabular-nums">{{ $entry->served_on->toDateString() }}</td>
                                <td class="py-2 pr-4">{{ $entry->meal->label() }}</td>
                                <td class="py-2 pr-4">{{ $entry->article?->name ?? __('Valfri rätt') }} @if($entry->corrects_meal_id)<x-ui.badge>{{ __('Rättelse') }}</x-ui.badge>@endif</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $qty($entry->quantity_milli) }}</td>
                                <td class="py-2 pr-4">{{ $entry->reason->label() }}@if($entry->employee_id !== null)<span class="block text-xs text-gray-500">{{ $canPayroll ? trim(($entry->employee?->first_name ?? '').' '.($entry->employee?->last_name ?? '')) : __('En anställd') }}</span>@endif</td>
                                <td class="py-2 pr-4 text-right">
                                    @if($canRegister && $entry->corrects_meal_id === null && $entry->correction === null)
                                        <button type="button" wire:click="correct({{ $entry->id }})" wire:confirm="{{ __('Rätta raden? Råvarorna läggs tillbaka i lagret och en rättelserad läggs till.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Rätta') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $entries->links() }}</div>
        @endif
    </x-ui.card>
</div>
