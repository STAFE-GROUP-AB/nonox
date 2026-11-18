@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $cell = 'w-24 rounded-lg border-stone-200 px-2 py-1 text-right text-sm tabular-nums focus:border-accent-500 focus:ring-accent-500 disabled:bg-stone-50 disabled:text-gray-700';
    $monthLabels = array_map(fn (string $month): string => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->locale(app()->getLocale())->isoFormat('MMM YY'), $months);
@endphp
<div class="space-y-6"
     x-data="{
        dirty: false,
        num(value) {
            const clean = String(value ?? '').replace(/[\s kr]/g, '').replace('−', '-').replace(',', '.');
            const parsed = Number(clean);
            return clean === '' || Number.isNaN(parsed) ? 0 : parsed;
        },
        fmt(value) {
            return new Intl.NumberFormat('sv-SE', { maximumFractionDigits: 0 }).format(value);
        },
        total(key) {
            return (this.$wire.cells[key] ?? []).reduce((sum, value) => sum + this.num(value), 0);
        },
        result(column) {
            return (this.$wire.rows ?? []).reduce((sum, row) => {
                const value = this.num((this.$wire.cells[row.key] ?? [])[column]);
                return sum + (row.revenue ? value : -value);
            }, 0);
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
        paste(event, row, column) {
            const text = (event.clipboardData || window.clipboardData).getData('text');
            if (! /[\t\n]/.test(text.trim())) return;
            event.preventDefault();
            this.dirty = true;
            this.$wire.pasteBlock(row, column, text);
        },
     }"
     x-on:budget-saved.window="dirty = false">

    <div class="flex flex-wrap items-center gap-3">
        @if($canChange)
            <x-button type="button" wire:click="save" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
        @endif
        <a href="{{ route('accounting.budgets') }}" wire:navigate class="text-sm font-medium text-gray-700 underline">{{ __('Alla budgetar') }}</a>
        <a href="{{ route('accounting.budgets.report', ['budget' => $version->id]) }}" wire:navigate class="text-sm font-medium text-gray-700 underline">{{ __('Budget mot utfall') }}</a>
        <a href="{{ route('accounting.budgets.csv', ['budgetVersion' => $version->id]) }}" class="text-sm font-medium text-gray-700 underline">{{ __('Ladda ner som CSV') }}</a>
        <span class="ms-auto flex flex-wrap items-center gap-2">
            @if($version->is_active)
                <x-ui.badge tone="accent">{{ __('Används i rapporterna') }}</x-ui.badge>
            @elseif($canManage)
                <x-secondary-button type="button" wire:click="activate">{{ __('Använd i rapporterna') }}</x-secondary-button>
            @endif
            <x-ui.badge :tone="$version->isLocked() ? 'neutral' : 'warning'">{{ $version->status->label() }}</x-ui.badge>
            @if($canChange)
                <x-secondary-button type="button" wire:click="lock" wire:confirm="{{ __('Lås budgeten :name? En låst budget kan inte ändras igen, bara kopieras till en ny budget.', ['name' => $version->name]) }}">{{ __('Lås budgeten') }}</x-secondary-button>
            @endif
        </span>
    </div>

    <p x-show="dirty" x-cloak class="text-sm font-medium text-amber-800">{{ __('Du har ändringar som inte är sparade.') }}</p>

    @if($version->isLocked())
        <p class="rounded-2xl bg-stone-50 px-4 py-3 text-sm text-gray-700 ring-1 ring-stone-200">{{ __('Budgeten är låst och kan inte ändras. Skapa en ny budget som en kopia av den här om du vill ändra något.') }}</p>
    @endif

    <x-ui.card :title="__('Budget per konto och månad')" :description="__('Enter går nedåt, Tab åt höger och Ctrl+Enter sparar. Skriv årets belopp i Summa så fördelas det jämnt över månaderna. Klistra in ett block från ett kalkylark i en ruta så fylls rutorna åt höger och nedåt.')">
        @if($rows === [])
            <x-ui.empty-state :title="__('Budgeten har inga rader ännu')" class="mt-4">{{ __('Lägg till ett konto nedan, eller läs in en CSV-fil.') }}</x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="sticky left-0 z-10 bg-white py-2 pe-4 font-medium">{{ __('Konto') }}</th>
                            @foreach($monthLabels as $label)
                                <th scope="col" class="px-1 py-2 text-right font-medium">{{ $label }}</th>
                            @endforeach
                            <th scope="col" class="px-1 py-2 text-right font-medium">{{ __('Summa') }}</th>
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
                            <tr wire:key="row-{{ $row['key'] }}">
                                <th scope="row" class="sticky left-0 z-10 bg-white py-1 pe-4 text-left font-normal">
                                    <span class="font-semibold tabular-nums text-ink">{{ $account?->number }}</span>
                                    <span class="text-gray-700">{{ $account?->name }}</span>
                                    <span class="ms-1 rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ ($row['revenue'] ?? false) ? __('Intäkt') : __('Kostnad') }}</span>
                                    @if($costCenter || $project)
                                        <span class="block text-xs text-gray-500">
                                            @if($costCenter){{ __('Kostnadsställe') }} {{ $costCenter->code }}@endif
                                            @if($costCenter && $project) · @endif
                                            @if($project){{ __('Projekt') }} {{ $project->code }}@endif
                                        </span>
                                    @endif
                                </th>
                                @foreach($months as $c => $month)
                                    <td class="px-1 py-1">
                                        <input type="text" inputmode="decimal"
                                               aria-label="{{ __(':account :month', ['account' => $account?->number, 'month' => $monthLabels[$c]]) }}"
                                               data-r="{{ $r }}" data-c="{{ $c }}"
                                               wire:model="cells.{{ $row['key'] }}.{{ $c }}"
                                               x-on:input="dirty = true"
                                               x-on:keydown="move($event, {{ $r }}, {{ $c }})"
                                               x-on:paste="paste($event, {{ $r }}, {{ $c }})"
                                               @disabled(! $canChange)
                                               @class([$cell, 'border-red-500 ring-1 ring-red-500' => $errors->has("cells.{$row['key']}.{$c}")])>
                                    </td>
                                @endforeach
                                <td class="px-1 py-1">
                                    <input type="text" inputmode="decimal"
                                           aria-label="{{ __('Summa för :account, fördelas jämnt', ['account' => $account?->number]) }}"
                                           x-bind:value="fmt(total('{{ $row['key'] }}'))"
                                           x-on:keydown.enter.prevent="dirty = true; $wire.spread('{{ $row['key'] }}', $event.target.value)"
                                           x-on:change="dirty = true; $wire.spread('{{ $row['key'] }}', $event.target.value)"
                                           @disabled(! $canChange)
                                           class="{{ $cell }} w-28 font-semibold">
                                    @error("cells.{$row['key']}")<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                                </td>
                                <td class="py-1 ps-2 whitespace-nowrap text-right">
                                    @if($canChange)
                                        <button type="button" wire:click="lastYear('{{ $row['key'] }}')" class="text-xs font-medium text-gray-700 underline">{{ __('Som i fjol') }}</button>
                                        <button type="button" wire:click="removeRow('{{ $row['key'] }}')" class="ms-2 text-xs font-medium text-gray-600 underline">{{ __('Ta bort') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-stone-300 font-semibold text-ink">
                            <th scope="row" class="sticky left-0 z-10 bg-white py-2 pe-4 text-left">{{ __('Resultat') }}</th>
                            @foreach($months as $c => $month)
                                <td class="px-2 py-2 text-right tabular-nums" x-text="fmt(result({{ $c }}))"></td>
                            @endforeach
                            <td class="px-2 py-2 text-right tabular-nums" x-text="fmt(resultTotal())"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($canChange)
        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card :title="__('Lägg till rad')" :description="__('Ett resultatkonto, och om du vill ett kostnadsställe och ett projekt.')">
                <form wire:submit="addRow" class="mt-4 grid gap-4 sm:grid-cols-3">
                    <x-ui.field for="budget_account" :label="__('Konto')" error="newAccount">
                        <select id="budget_account" class="{{ $input }} mt-1" wire:model="newAccount">
                            <option value="">{{ __('Välj konto') }}</option>
                            @foreach($accounts->where('is_active', true) as $account)
                                <option value="{{ $account->number }}">{{ $account->number }} {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="budget_cost_center" :label="__('Kostnadsställe')">
                        <select id="budget_cost_center" class="{{ $input }} mt-1" wire:model="newCostCenter">
                            <option value="">{{ __('Inget') }}</option>
                            @foreach($costCenters->where('is_active', true) as $object)
                                <option value="{{ $object->code }}">{{ $object->code }} {{ $object->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="budget_project" :label="__('Projekt')">
                        <select id="budget_project" class="{{ $input }} mt-1" wire:model="newProject">
                            <option value="">{{ __('Inget') }}</option>
                            @foreach($projects->where('is_active', true) as $object)
                                <option value="{{ $object->code }}">{{ $object->code }} {{ $object->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <div class="sm:col-span-3"><x-button type="submit">{{ __('Lägg till rad') }}</x-button></div>
                </form>
            </x-ui.card>

            <x-ui.card :title="__('Som i fjol')" :description="__('Fyll alla rader med förra årets utfall för samma månad, avrundat till hela kronor. En rad utan kostnadsställe eller projekt får hela kontots utfall.')">
                <div class="mt-4 flex flex-wrap items-end gap-4">
                    <x-ui.field for="budget_last_year_percent" :label="__('Förändring i procent')" error="percent">
                        <input id="budget_last_year_percent" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="percent" placeholder="{{ __('Till exempel 5 eller -2,5') }}">
                    </x-ui.field>
                    <x-secondary-button type="button" wire:click="lastYear" wire:confirm="{{ __('Ersätta beloppen på alla rader med fjolårets utfall?') }}">{{ __('Fyll alla rader som i fjol') }}</x-secondary-button>
                </div>
                <p class="mt-2 text-xs text-gray-500">{{ __('Procentsatsen gäller också knappen Som i fjol på en enskild rad.') }}</p>
            </x-ui.card>
        </div>

        <x-ui.card :title="__('Läs in från kalkylark eller CSV')" :description="__('En rad per konto. Första raden kan vara rubriker: Konto, Kostnadsställe, Projekt och en kolumn per månad (ÅÅÅÅ-MM), som i filen från Ladda ner som CSV. Utan rubriker är första kolumnen kontot och resten årets månader i ordning. Raderna läggs i rutnätet; spara för att behålla dem.')">
            <form wire:submit="import" class="mt-4 space-y-4">
                <x-ui.field for="budget_file" :label="__('CSV-fil')" error="file">
                    <input id="budget_file" type="file" accept=".csv,text/csv,text/plain" wire:model="file" class="mt-1 block text-sm">
                </x-ui.field>
                <x-ui.field for="budget_paste" :label="__('Eller klistra in rader')">
                    <textarea id="budget_paste" rows="4" class="{{ $input }} mt-1 font-mono text-xs" wire:model="paste"></textarea>
                </x-ui.field>
                @if($importErrors !== [])
                    <div class="rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                        <p class="font-semibold">{{ __('Inget lästes in. Rätta raderna och försök igen:') }}</p>
                        <ul class="mt-1 list-disc ps-5">
                            @foreach($importErrors as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <x-secondary-button type="submit" wire:loading.attr="disabled">{{ __('Läs in') }}</x-secondary-button>
            </form>
        </x-ui.card>
    @endif
</div>
