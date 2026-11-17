@php
    use App\Modules\Planning\Actions\SaveBudget;
    use App\Modules\Planning\Domain\Budget\BudgetLine;
    use App\Modules\Planning\Support\Budget\BudgetText;
    use App\Support\Money\Money;

    $locale = app()->getLocale();
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $money = fn (?int $ore): string => $ore === null ? '–' : Money::ofOre($ore)->format();
    $scopeName = $scopeArea?->name ?? __('Hela företaget');
    $statusClass = [BudgetLine::OVER => 'bg-red-50 text-red-800', BudgetLine::NEAR => 'bg-amber-50 text-amber-900', BudgetLine::OK => 'bg-emerald-50 text-emerald-800', BudgetLine::UNKNOWN => 'text-gray-500'];
    $statusLabel = [BudgetLine::OVER => __('Över budget'), BudgetLine::NEAR => __('Nära budget'), BudgetLine::OK => __('Inom budget'), BudgetLine::UNKNOWN => __('Inget belopp')];
@endphp

<div class="space-y-6">
    {{-- Scope and weeks --}}
    <div class="flex flex-wrap items-end justify-between gap-3 rounded-3xl bg-white p-4 shadow-xs ring-1 ring-stone-200">
        <label class="block text-sm">
            <span class="font-medium text-gray-700">{{ __('Gäller') }}</span>
            <select wire:model.live="area" class="{{ $input }} w-56">
                <option value="">{{ __('Hela företaget') }}</option>
                @foreach($areas as $one)
                    <option value="{{ $one->id }}">{{ $one->name }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="previous" class="rounded-xl px-3 py-2 text-sm font-semibold ring-1 ring-stone-200 hover:bg-stone-50" aria-label="{{ __('Föregående veckor') }}">‹</button>
            <p class="min-w-44 text-center text-sm font-bold text-ink tabular-nums">{{ __('Vecka :from–:to', ['from' => $first->isoWeek(), 'to' => $last->isoWeek()]) }} · {{ $first->locale($locale)->isoFormat('D MMM') }}–{{ $last->locale($locale)->isoFormat('D MMM YYYY') }}</p>
            <button type="button" wire:click="next" class="rounded-xl px-3 py-2 text-sm font-semibold ring-1 ring-stone-200 hover:bg-stone-50" aria-label="{{ __('Nästa veckor') }}">›</button>
            <button type="button" wire:click="thisWeek" class="rounded-xl px-3 py-2 text-sm font-semibold text-accent-700 hover:bg-accent-50">{{ __('Den här veckan') }}</button>
        </div>
    </div>

    {{-- The weeks --}}
    <x-ui.card :title="__('Budget och beräknad kostnad för :scope', ['scope' => $scopeName])" :description="__('Den beräknade kostnaden är summan av passens uppskattade lönekostnad, med samma regler som i schemat. * betyder att någon del av kostnaden saknas.')">
        <div class="-mx-2 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-stone-200 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th scope="col" class="px-3 py-2">{{ __('Vecka') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('Budget') }}</th>
                        <th scope="col" class="px-3 py-2 text-right">{{ __('Beräknad kostnad') }}</th>
                        <th scope="col" class="px-3 py-2 text-right">{{ __('Andel av budgeten') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('Läge') }}</th>
                        @if($canBudget)<th scope="col" class="px-3 py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($rows as $row)
                        @php($line = $row['line'] ?? $row['total'])
                        <tr wire:key="week-{{ $row['monday']->toDateString() }}" @class(['bg-accent-50/30' => $row['monday']->toDateString() === $currentMonday])>
                            <th scope="row" class="whitespace-nowrap px-3 py-2 text-left font-medium text-ink">
                                {{ __('Vecka :week', ['week' => $row['monday']->isoWeek()]) }}
                                <span class="block text-xs font-normal text-gray-500">{{ $row['monday']->locale($locale)->isoFormat('D MMM') }}–{{ $row['monday']->addDays(6)->locale($locale)->isoFormat('D MMM') }}</span>
                            </th>
                            <td class="px-3 py-2">
                                @if($line === null)
                                    <span class="text-gray-500">{{ __('Ingen budget') }}</span>
                                @else
                                    {{ BudgetText::budget($line) }}
                                    @if($row['total'] !== null)<span class="block text-xs text-gray-500">{{ __('Summan av avdelningarnas budgetar') }}</span>@endif
                                    @if($note = BudgetText::forecastNote($line))<span class="block text-xs text-gray-500">{{ $note }}</span>@endif
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ $line === null ? '–' : $money($line->costOre).($line->incomplete ? ' *' : '') }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ $line === null ? '–' : BudgetText::percent($line->usedPercent()) }}</td>
                            <td class="px-3 py-2">
                                @if($line !== null)
                                    <span class="whitespace-nowrap rounded-lg px-2 py-0.5 text-xs font-semibold {{ $statusClass[$line->status()] }}">{{ $statusLabel[$line->status()] }}</span>
                                @endif
                            </td>
                            @if($canBudget)
                                <td class="px-3 py-2 text-right">
                                    <span class="flex justify-end gap-3 whitespace-nowrap text-sm font-semibold">
                                        <button type="button" wire:click="editWeek('{{ $row['monday']->toDateString() }}')" class="text-accent-700 underline">{{ $row['budget_id'] === null ? __('Lägg till') : __('Ändra') }}</button>
                                        @if($row['budget_id'] !== null)
                                            <button type="button" wire:click="delete({{ $row['budget_id'] }})" wire:confirm="{{ __('Ta bort budgeten för veckan? Passen påverkas inte.') }}" class="text-gray-700 underline">{{ __('Ta bort') }}</button>
                                        @endif
                                    </span>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-500">{{ __('Schemat varnar från :percent av budgeten och vid 100 %. Varningarna stoppar ingenting, och kostnaden är en uppskattning: lönen räknas alltid i lönekörningen.', ['percent' => $warnAtLabel]) }}</p>
    </x-ui.card>

    @if($canBudget)
        <x-ui.card :title="__('Sätt budget')" :description="__('För en vecka eller flera i rad, för :scope. Ett fast belopp, eller en andel av veckans prognos för försäljningen från Bemanningsbehov. Det som redan finns för veckorna ersätts.', ['scope' => $scopeName])">
            <form wire:submit="save" class="grid gap-5 sm:grid-cols-4">
                <x-ui.field for="budget-from" :label="__('Från vecka')" :hint="__('Välj en dag i veckan.')" error="form.from">
                    <input id="budget-from" type="date" wire:model="form.from" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="budget-to" :label="__('Till vecka')" :hint="__('Tomt: bara den första veckan.')" error="form.to">
                    <input id="budget-to" type="date" wire:model="form.to" class="{{ $input }}">
                </x-ui.field>
                <fieldset class="text-sm">
                    <legend class="font-medium text-gray-700">{{ __('Typ av budget') }}</legend>
                    <label class="mt-2 flex items-center gap-2"><input type="radio" wire:model.live="form.kind" value="{{ SaveBudget::AMOUNT }}" class="text-accent-600 focus:ring-accent-500"> {{ __('Belopp per vecka') }}</label>
                    <label class="mt-1 flex items-center gap-2"><input type="radio" wire:model.live="form.kind" value="{{ SaveBudget::PERCENT }}" class="text-accent-600 focus:ring-accent-500"> {{ __('Andel av prognosen') }}</label>
                </fieldset>
                <x-ui.field for="budget-value" :label="$form['kind'] === SaveBudget::PERCENT ? __('Andel i procent') : __('Belopp i kronor')" :hint="$form['kind'] === SaveBudget::PERCENT ? __('Till exempel 30 eller 28,5.') : __('Lönekostnaden för veckan, till exempel 25 000.')" error="form.value">
                    <input id="budget-value" wire:model="form.value" inputmode="decimal" placeholder="{{ $form['kind'] === SaveBudget::PERCENT ? '30' : '25 000' }}" class="{{ $input }} tabular-nums" required>
                </x-ui.field>
                <div class="sm:col-span-4"><x-button type="submit">{{ __('Spara budget') }}</x-button></div>
            </form>
        </x-ui.card>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card :title="__('Kopiera en veckas budget framåt')" :description="__('Hela företagets och alla avdelningars budget för veckan kopieras till de följande veckorna. En vecka som redan har en budget behåller den.')">
                <form wire:submit="copyForward" class="grid gap-5 sm:grid-cols-2">
                    <x-ui.field for="copy-week" :label="__('Vecka att kopiera')" error="copy.week">
                        <input id="copy-week" type="date" wire:model="copy.week" class="{{ $input }}" required>
                    </x-ui.field>
                    <x-ui.field for="copy-times" :label="__('Antal veckor framåt')" error="copy.times">
                        <input id="copy-times" type="number" min="1" max="52" wire:model="copy.times" class="{{ $input }} w-28 tabular-nums" required>
                    </x-ui.field>
                    <div class="sm:col-span-2"><x-button type="submit">{{ __('Kopiera') }}</x-button></div>
                </form>
            </x-ui.card>

            <x-ui.card :title="__('Varna från')" :description="__('Schemat varnar när den beräknade kostnaden når den här andelen av en veckas budget, och alltid vid 100 %.')">
                <form wire:submit="saveWarnAt" class="flex flex-wrap items-end gap-4">
                    <x-ui.field for="warn-at" :label="__('Andel av budgeten i procent')" error="warnAt">
                        <input id="warn-at" wire:model="warnAt" inputmode="decimal" placeholder="95" class="{{ $input }} w-28 tabular-nums" required>
                    </x-ui.field>
                    <x-button type="submit">{{ __('Spara') }}</x-button>
                </form>
            </x-ui.card>
        </div>
    @else
        <p class="text-sm text-gray-600">{{ __('Budgeten sätts av ägare, administratörer och löneadministratörer.') }}</p>
    @endif
</div>
