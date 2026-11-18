@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $cell = 'w-24 rounded-lg border-stone-200 px-2 py-1 text-right text-sm tabular-nums placeholder:text-gray-500 focus:border-accent-500 focus:ring-accent-500 disabled:bg-stone-50 disabled:text-gray-700';
    $monthLabel = fn (string $month): string => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->locale(app()->getLocale())->isoFormat('MMM YY');
    $longMonth = fn (string $month): string => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->locale(app()->getLocale())->isoFormat('MMMM YYYY');
    // Whole kronor, half up, integer arithmetic only (AGENTS.md §1.2).
    $kr = function (string $typed): string {
        $ore = $typed === '' ? 0 : (\App\Domain\Budget\BudgetMonths::parseCell($typed) ?? 0);
        $kronor = intdiv(abs($ore) + 50, 100);

        return $ore === 0 ? '' : ($ore < 0 && $kronor > 0 ? '−' : '').number_format($kronor, 0, ',', "\u{00A0}");
    };
    $defaultFor = fn (bool $revenue): string => (\App\Domain\Budget\ForecastMethod::tryFrom($revenue ? $revenueMethod : $costMethod) ?? \App\Domain\Budget\ForecastMethod::Average)->label();
@endphp
<div class="space-y-6"
     x-data="{
        dirty: false,
        num(value) {
            const clean = String(value ?? '').replace(/[\s kr]/g, '').replace('−', '-').replace(',', '.');
            const parsed = Number(clean);
            return clean === '' || Number.isNaN(parsed) ? 0 : parsed;
        },
        fmt(value) {
            return new Intl.NumberFormat('sv-SE', { maximumFractionDigits: 0 }).format(value);
        },
        value(key, column) {
            const typed = (this.$wire.cells[key] ?? [])[column];
            return typed !== undefined && typed !== null && String(typed).trim() !== '' ? this.num(typed) : this.num((this.$wire.computed[key] ?? [])[column]);
        },
        typed(key, column) {
            const typed = (this.$wire.cells[key] ?? [])[column];
            return typed !== undefined && typed !== null && String(typed).trim() !== '';
        },
        total(key) {
            return (this.$wire.months ?? []).reduce((sum, month, column) => sum + this.value(key, column), 0);
        },
        result(column) {
            return (this.$wire.rows ?? []).reduce((sum, row) => sum + (row.revenue ? 1 : -1) * this.value(row.key, column), 0);
        },
        resultTotal() {
            return (this.$wire.rows ?? []).reduce((sum, row) => sum + (row.revenue ? 1 : -1) * this.total(row.key), 0);
        },
        move(event, row, column) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault();
                this.$wire.save();
                return;
            }
            let step = 0;
            if (event.key === 'Enter') step = event.shiftKey ? -1 : 1;
            if (event.key === 'ArrowDown') step = 1;
            if (event.key === 'ArrowUp') step = -1;
            if (step === 0) return;
            event.preventDefault();
            const next = this.$root.querySelector(`[data-r='${row + step}'][data-c='${column}']`);
            if (next) { next.focus(); next.select(); }
        },
     }"
     x-on:forecast-saved.window="dirty = false">

    <div class="flex flex-wrap items-center gap-3">
        @if($canChange)
            <x-button type="button" wire:click="save" wire:loading.attr="disabled">{{ __('Spara och räkna om') }}</x-button>
        @endif
        <a href="{{ route('accounting.budgets') }}" wire:navigate class="text-sm font-medium text-gray-700 underline">{{ __('Alla budgetar') }}</a>
        <a href="{{ route('accounting.budgets.rolling') }}" wire:navigate class="text-sm font-medium text-gray-700 underline">{{ __('Rullande 12 månader') }}</a>
        <a href="{{ route('accounting.budgets.csv', ['budgetVersion' => $version->id]) }}" class="text-sm font-medium text-gray-700 underline">{{ __('Ladda ner som CSV') }}</a>
        <span class="ms-auto flex flex-wrap items-center gap-2">
            @if($version->is_active)
                <x-ui.badge tone="accent">{{ __('Används i Rullande 12 månader') }}</x-ui.badge>
            @elseif($canManage)
                <x-secondary-button type="button" wire:click="activate">{{ __('Använd i Rullande 12 månader') }}</x-secondary-button>
            @endif
            <x-ui.badge :tone="$version->isLocked() ? 'neutral' : 'warning'">{{ $version->status->label() }}</x-ui.badge>
            @if($canChange)
                <x-secondary-button type="button" wire:click="lock" wire:confirm="{{ __('Lås prognosen :name? En låst prognos kan inte ändras eller räknas om, bara användas som grund för en ny prognos.', ['name' => $version->name]) }}">{{ __('Lås prognosen') }}</x-secondary-button>
            @endif
        </span>
    </div>

    <p x-show="dirty" x-cloak class="text-sm font-medium text-amber-800">{{ __('Du har ändringar som inte är sparade.') }}</p>

    @if($errors->any())
        <div class="rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <p class="font-semibold">{{ __('Prognosen sparades inte:') }}</p>
            <ul class="mt-1 list-disc ps-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($version->isLocked())
        <p class="rounded-2xl bg-stone-50 px-4 py-3 text-sm text-gray-700 ring-1 ring-stone-200">{{ __('Prognosen är låst och kan inte ändras eller räknas om. Gör en ny prognos från den här under Budget om du vill ändra något.') }}</p>
    @endif

    <x-ui.card :title="__('Så räknas prognosen')" :description="__('Utfallet till och med brytmånaden kommer från bokföringen. Månaderna efter den räknas med radens metod. Ett belopp du skriver själv gäller i stället och markeras; töm rutan så gäller metoden igen.')">
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5" data-forecast-settings>
            <x-ui.field for="forecast_cutoff" :label="__('Utfall till och med')" error="cutoff">
                <select id="forecast_cutoff" class="{{ $input }} mt-1" wire:model="cutoff" x-on:change="dirty = true" @disabled(! $canChange)>
                    @foreach($cutoffs as $month)
                        <option value="{{ $month }}">{{ $longMonth($month) }}</option>
                    @endforeach
                    @unless(in_array($storedCutoff, $cutoffs, true))
                        <option value="{{ $storedCutoff }}">{{ $longMonth($storedCutoff) }}</option>
                    @endunless
                </select>
            </x-ui.field>
            <x-ui.field for="forecast_revenue" :label="__('Metod för intäkter')" error="revenue_method">
                <select id="forecast_revenue" class="{{ $input }} mt-1" wire:model="revenueMethod" x-on:change="dirty = true" @disabled(! $canChange)>
                    @foreach($methods as $method)
                        <option value="{{ $method->value }}">{{ $method->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="forecast_costs" :label="__('Metod för kostnader')" error="cost_method">
                <select id="forecast_costs" class="{{ $input }} mt-1" wire:model="costMethod" x-on:change="dirty = true" @disabled(! $canChange)>
                    @foreach($methods as $method)
                        <option value="{{ $method->value }}">{{ $method->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="forecast_average" :label="__('Månader i snittet')" error="average_months">
                <input id="forecast_average" type="number" min="1" max="12" class="{{ $input }} mt-1" wire:model="averageMonths" x-on:input="dirty = true" @disabled(! $canChange)>
            </x-ui.field>
            <x-ui.field for="forecast_growth" :label="__('Förändring mot i fjol i procent')" error="growth">
                <input id="forecast_growth" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="growth" x-on:input="dirty = true" placeholder="{{ __('Till exempel 5 eller -2,5') }}" @disabled(! $canChange)>
            </x-ui.field>
        </div>
        <ul class="mt-3 space-y-1 text-sm text-gray-700" data-forecast-notes>
            <li>{{ App\Domain\Budget\ForecastEngine::averageNote($averageWindow) }}</li>
            @unless($hasPreviousYear)
                <li>{{ __('Det finns inget föregående räkenskapsår, så Samma månad i fjol ger 0.') }}</li>
            @endunless
            @unless($hasBudget)
                <li>{{ __('Räkenskapsåret har ingen budget som används i rapporterna, så metoden Budgeten kan inte väljas.') }}</li>
            @endunless
            @if($cutoffUnlocked)
                <li class="font-medium text-amber-800">{{ __('Brytmånaden är inte låst, så den kan sakna verifikationer som bokförs i efterhand.') }}</li>
            @endif
        </ul>
    </x-ui.card>

    <x-ui.card :title="__('Prognos per konto och månad')" :description="__('Grå kolumner är utfall. En markerad ruta är ändrad för hand. Enter går nedåt, Tab åt höger och Ctrl+Enter sparar och räknar om.')">
        @if($rows === [])
            <x-ui.empty-state :title="__('Prognosen har inga rader ännu')" class="mt-4">{{ __('Det finns varken utfall eller budget att räkna med. Lägg till ett konto nedan och skriv beloppen själv.') }}</x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm" data-forecast-grid>
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="sticky left-0 z-10 bg-white py-2 pe-4 font-medium">{{ __('Konto') }}</th>
                            @foreach($months as $c => $month)
                                <th scope="col" @class(['px-1 py-2 text-right font-medium', 'bg-stone-50' => $month <= $storedCutoff])>
                                    <span class="block">{{ $monthLabel($month) }}</span>
                                    <span class="block text-xs font-normal">{{ $month <= $storedCutoff ? __('Utfall') : __('Prognos') }}</span>
                                </th>
                            @endforeach
                            <th scope="col" class="px-1 py-2 text-right font-medium">{{ __('Summa') }}</th>
                            <th scope="col" class="px-2 py-2 font-medium">{{ __('Metod') }}</th>
                            <th scope="col" class="py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($rows as $r => $row)
                            @php
                                $account = $accounts[$row['account_id']] ?? null;
                                $costCenter = $row['cost_center_id'] === null ? null : ($costCenters[$row['cost_center_id']] ?? null);
                                $project = $row['project_id'] === null ? null : ($projects[$row['project_id']] ?? null);
                            @endphp
                            <tr wire:key="forecast-row-{{ $row['key'] }}">
                                <th scope="row" class="sticky left-0 z-10 bg-white py-1 pe-4 text-left font-normal">
                                    <span class="font-semibold tabular-nums text-ink">{{ $account?->number }}</span>
                                    <span class="text-gray-700">{{ $account?->name }}</span>
                                    <span class="ms-1 rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ $row['revenue'] ? __('Intäkt') : __('Kostnad') }}</span>
                                    @if($costCenter || $project)
                                        <span class="block text-xs text-gray-500">
                                            @if($costCenter){{ __('Kostnadsställe') }} {{ $costCenter->code }}@endif
                                            @if($costCenter && $project) · @endif
                                            @if($project){{ __('Projekt') }} {{ $project->code }}@endif
                                        </span>
                                    @endif
                                </th>
                                @foreach($months as $c => $month)
                                    @if($month <= $storedCutoff)
                                        <td class="bg-stone-50 px-2 py-1 text-right tabular-nums text-gray-700">{{ $kr($computed[$row['key']][$c]) }}</td>
                                    @else
                                        <td class="px-1 py-1">
                                            <input type="text" inputmode="decimal"
                                                   aria-label="{{ __(':account :month', ['account' => $account?->number, 'month' => $monthLabel($month)]) }}"
                                                   data-r="{{ $r }}" data-c="{{ $c }}"
                                                   wire:model="cells.{{ $row['key'] }}.{{ $c }}"
                                                   placeholder="{{ $computed[$row['key']][$c] === '' ? '0' : $computed[$row['key']][$c] }}"
                                                   title="{{ __('Tom ruta: radens metod. Skriv ett belopp för att ändra månaden för hand.') }}"
                                                   x-on:input="dirty = true"
                                                   x-on:keydown="move($event, {{ $r }}, {{ $c }})"
                                                   x-bind:class="typed('{{ $row['key'] }}', {{ $c }}) ? 'bg-accent-50 ring-1 ring-accent-500 font-semibold' : ''"
                                                   @disabled(! $canChange)
                                                   @class([$cell, 'border-red-500 ring-1 ring-red-500' => $errors->has("cells.{$row['key']}.{$c}")])>
                                        </td>
                                    @endif
                                @endforeach
                                <td class="px-2 py-1 text-right font-semibold tabular-nums" x-text="fmt(total('{{ $row['key'] }}'))"></td>
                                <td class="px-2 py-1">
                                    <label for="forecast_method_{{ $r }}" class="sr-only">{{ __('Metod för :account', ['account' => $account?->number]) }}</label>
                                    <select id="forecast_method_{{ $r }}" class="rounded-lg border-stone-200 py-1 text-xs focus:border-accent-500 focus:ring-accent-500" wire:model="rowMethods.{{ $row['key'] }}" x-on:change="dirty = true" @disabled(! $canChange)>
                                        <option value="">{{ __('Standard: :method', ['method' => $defaultFor($row['revenue'])]) }}</option>
                                        @foreach($methods as $method)
                                            <option value="{{ $method->value }}">{{ $method->label() }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="py-1 ps-2 whitespace-nowrap text-right">
                                    @if($canChange)
                                        <button type="button" wire:click="resetRow('{{ $row['key'] }}')" x-on:click="dirty = true" class="text-xs font-medium text-gray-700 underline">{{ __('Följ metoden') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-stone-300 font-semibold text-ink">
                            <th scope="row" class="sticky left-0 z-10 bg-white py-2 pe-4 text-left">{{ __('Resultat') }}</th>
                            @foreach($months as $c => $month)
                                <td @class(['px-2 py-2 text-right tabular-nums', 'bg-stone-50' => $month <= $storedCutoff]) x-text="fmt(result({{ $c }}))"></td>
                            @endforeach
                            <td class="px-2 py-2 text-right tabular-nums" x-text="fmt(resultTotal())"></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($canChange)
        <x-ui.card :title="__('Lägg till rad')" :description="__('Ett resultatkonto som varken har utfall eller budget, och om du vill ett kostnadsställe och ett projekt. Skriv beloppen för månaderna efter brytmånaden; en rad utan belopp försvinner när prognosen räknas om.')">
            <form wire:submit="addRow" class="mt-4 grid gap-4 sm:grid-cols-4">
                <x-ui.field for="forecast_account" :label="__('Konto')" error="newAccount">
                    <select id="forecast_account" class="{{ $input }} mt-1" wire:model="newAccount">
                        <option value="">{{ __('Välj konto') }}</option>
                        @foreach($accounts->where('is_active', true) as $option)
                            <option value="{{ $option->number }}">{{ $option->number }} {{ $option->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="forecast_cost_center" :label="__('Kostnadsställe')">
                    <select id="forecast_cost_center" class="{{ $input }} mt-1" wire:model="newCostCenter">
                        <option value="">{{ __('Inget') }}</option>
                        @foreach($costCenters->where('is_active', true) as $object)
                            <option value="{{ $object->code }}">{{ $object->code }} {{ $object->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="forecast_project" :label="__('Projekt')">
                    <select id="forecast_project" class="{{ $input }} mt-1" wire:model="newProject">
                        <option value="">{{ __('Inget') }}</option>
                        @foreach($projects->where('is_active', true) as $object)
                            <option value="{{ $object->code }}">{{ $object->code }} {{ $object->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <div class="flex items-end"><x-secondary-button type="submit">{{ __('Lägg till rad') }}</x-secondary-button></div>
            </form>
        </x-ui.card>
    @endif
</div>
