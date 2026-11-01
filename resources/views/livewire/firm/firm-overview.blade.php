@php
    $select = 'block w-full rounded-xl border-gray-300 py-2 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $money = fn (?int $ore) => App\Support\Money\Money::ofOre((int) $ore);
    $totals = $data['totals'];
    $statusTone = [
        'TODO' => 'bg-stone-100 text-gray-800',
        'IN_PROGRESS' => 'bg-sky-50 text-sky-800',
        'WAITING_FOR_CLIENT' => 'bg-amber-50 text-amber-800',
        'DONE' => 'bg-green-50 text-green-800',
    ];
@endphp
<div class="space-y-6">
    {{-- The call to action first, on the left; reload on the right. --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <livewire:tasks.task-form />
        <div class="flex items-center gap-3 text-sm text-gray-500">
            <span>{{ __('Uppdaterad :time', ['time' => \Carbon\CarbonImmutable::parse($data['generated_at'])->timezone(config('app.timezone'))->format('H:i')]) }}</span>
            <button type="button" wire:click="reload" wire:loading.attr="disabled" class="rounded-xl bg-white px-3 py-2 font-semibold text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300">{{ __('Läs in på nytt') }}</button>
        </div>
    </div>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Summor för alla företag') }}">
        <div class="rounded-3xl bg-ink px-5 py-4 text-white shadow-xs">
            <span class="block text-sm text-stone-300">{{ __('Bank och kassa') }}</span>
            <x-ui.amount :money="$money($totals['bank'])" on-dark class="mt-1 block text-2xl font-bold tracking-tight text-white" />
            <span class="mt-2 block text-xs text-stone-300">{{ __(':count företag, enligt bokföringen', ['count' => $totals['companies']]) }}</span>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <span class="block text-sm text-gray-500">{{ __('Kundfordringar') }}</span>
            <x-ui.amount :money="$money($totals['receivables'])" class="mt-1 block text-2xl font-bold tracking-tight text-ink" />
            <span class="mt-2 block text-xs text-gray-500">{{ __('Obetalda kundfakturor') }}</span>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <span class="block text-sm text-gray-500">{{ __('Leverantörsskulder') }}</span>
            <x-ui.amount :money="$money($totals['payables'])" class="mt-1 block text-2xl font-bold tracking-tight text-ink" />
            <span class="mt-2 block text-xs text-gray-500">{{ __('Obetalda leverantörsfakturor') }}</span>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <span class="block text-sm text-gray-500">{{ $totals['vat'] >= 0 ? __('Moms att betala') : __('Moms att få tillbaka') }}</span>
            <x-ui.amount :money="$money(abs($totals['vat']))" class="mt-1 block text-2xl font-bold tracking-tight text-ink" />
            <span class="mt-2 block text-xs text-gray-500">{{ __('Saldo på momskontona, netto för alla företag') }}</span>
        </div>
    </section>

    <section class="rounded-3xl bg-white shadow-xs ring-1 ring-stone-200">
        <header class="flex flex-wrap items-end justify-between gap-4 border-b border-stone-200 px-5 py-5 sm:px-6">
            <div>
                <h2 class="text-lg font-bold tracking-tight text-ink">{{ __('Att göra') }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __(':overdue försenade · :week denna vecka · :waiting väntar på kund', ['overdue' => $totals['overdue'], 'week' => $totals['due_week'], 'waiting' => $totals['waiting']]) }}
                </p>
            </div>
            @if($views->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-gray-500">{{ __('Sparade vyer:') }}</span>
                    @foreach($views as $view)
                        <span class="inline-flex items-center rounded-full bg-stone-100 ring-1 ring-stone-200" wire:key="view-{{ $view->id }}">
                            <button type="button" class="px-3 py-1 font-medium text-gray-800 hover:text-ink" wire:click="applyView({{ $view->id }})">{{ $view->name }}</button>
                            <button type="button" class="pe-2 text-gray-400 hover:text-accent-700" wire:click="deleteView({{ $view->id }})" aria-label="{{ __('Ta bort vyn :name', ['name' => $view->name]) }}">×</button>
                        </span>
                    @endforeach
                </div>
            @endif
        </header>

        <div class="grid gap-3 border-b border-stone-200 px-5 py-4 sm:grid-cols-2 sm:px-6 lg:grid-cols-5">
            <div>
                <label for="filter_responsible" class="text-xs font-medium text-gray-500">{{ __('Ansvarig konsult') }}</label>
                <select id="filter_responsible" class="{{ $select }} mt-1" wire:model.live="responsible">
                    <option value="">{{ __('Alla') }}</option>
                    <option value="ingen">{{ __('Ingen ansvarig') }}</option>
                    @foreach($data['consultants'] as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter_deadline" class="text-xs font-medium text-gray-500">{{ __('Sista dag') }}</label>
                <select id="filter_deadline" class="{{ $select }} mt-1" wire:model.live="deadline">
                    <option value="">{{ __('När som helst') }}</option>
                    <option value="forsenad">{{ __('Försenade') }}</option>
                    <option value="vecka">{{ __('Denna vecka') }}</option>
                    <option value="manad">{{ __('Denna månad') }}</option>
                </select>
            </div>
            <div>
                <label for="filter_type" class="text-xs font-medium text-gray-500">{{ __('Typ') }}</label>
                <select id="filter_type" class="{{ $select }} mt-1" wire:model.live="type">
                    <option value="">{{ __('Alla typer') }}</option>
                    @foreach($types as $option)
                        <option value="{{ $option->slug() }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter_status" class="text-xs font-medium text-gray-500">{{ __('Status') }}</label>
                <select id="filter_status" class="{{ $select }} mt-1" wire:model.live="status">
                    <option value="">{{ __('Alla öppna') }}</option>
                    @foreach($statuses as $option)
                        @continue($option === App\Domain\Tasks\TaskStatus::Done)
                        <option value="{{ $option->slug() }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col justify-end gap-2">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="mine">
                    {{ __('Bara mina uppgifter') }}
                </label>
                @if($filtered)
                    <button type="button" class="self-start text-sm text-gray-600 underline" wire:click="clearFilters">{{ __('Rensa filter') }}</button>
                @endif
            </div>
        </div>

        @if($filtered)
            <form wire:submit="saveView" class="flex flex-wrap items-end gap-2 border-b border-stone-200 px-5 py-3 sm:px-6">
                <div class="min-w-48 flex-1 sm:max-w-xs">
                    <label for="view_name" class="text-xs font-medium text-gray-500">{{ __('Spara filtren som vy') }}</label>
                    <input id="view_name" type="text" maxlength="60" class="{{ $select }} mt-1" wire:model="viewName" placeholder="{{ __('Till exempel: Mina moms denna vecka') }}">
                    <x-input-error for="viewName" class="mt-1" />
                </div>
                <button type="submit" class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300">{{ __('Spara vyn') }}</button>
            </form>
        @endif

        @if($items === [])
            <div class="px-5 py-10 sm:px-6">
                <x-ui.empty-state :title="$filtered ? __('Inget matchar filtren') : __('Inget väntar')">
                    {{ $filtered ? __('Ändra eller rensa filtren för att se mer.') : __('Alla dina företag är à jour. Skapa en uppgift om du vill lämna något till en kollega eller kunden.') }}
                </x-ui.empty-state>
            </div>
        @else
            <ul class="divide-y divide-stone-200">
                @foreach($items as $item)
                    <li wire:key="item-{{ $item['key'] }}" class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:gap-4 sm:px-6">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                                <span class="font-semibold uppercase tracking-wide text-gray-700">{{ $item['company_name'] }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ $item['type_label'] }}</span>
                                @if($item['source'] === 'task')
                                    <span class="rounded-full px-2 py-0.5 font-semibold {{ $statusTone[$item['status']] ?? '' }}">{{ $item['status_label'] }}</span>
                                @endif
                            </p>
                            <p class="mt-1 font-medium text-ink">
                                @if($item['source'] === 'system' && $item['count'] > 1)
                                    <span class="tabular-nums font-bold">{{ $item['count'] }}</span>
                                @endif
                                {{ $item['label'] }}
                            </p>
                            <p class="mt-0.5 flex flex-wrap gap-x-3 text-sm text-gray-600">
                                @if($item['due'])
                                    <span @class(['tabular-nums', 'font-semibold text-accent-700' => $item['overdue']])>{{ $item['overdue'] ? __('Försenad, sista dag var :date', ['date' => $item['due']]) : __('Sista dag :date', ['date' => $item['due']]) }}</span>
                                @endif
                                @if($item['assignee_name'])
                                    <span>{{ __('Ansvarig: :name', ['name' => $item['assignee_name']]) }}</span>
                                @elseif($item['source'] === 'task')
                                    <span class="text-gray-500">{{ __('Ingen ansvarig') }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="shrink-0">
                            @include('firm.open-in-company', [
                                'companyId' => $item['company_id'],
                                'taskId' => $item['task_id'],
                                'destination' => $item['destination'],
                                'label' => $item['source'] === 'task' ? __('Öppna uppgiften') : __('Öppna i företaget'),
                                'class' => 'w-full rounded-xl bg-paper px-4 py-2 text-sm font-semibold text-accent-700 ring-1 ring-stone-200 hover:ring-stone-300 sm:w-auto',
                            ])
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="rounded-3xl bg-white shadow-xs ring-1 ring-stone-200">
        <button type="button" wire:click="$toggle('showFigures')" class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left sm:px-6" aria-expanded="{{ $showFigures ? 'true' : 'false' }}">
            <span class="text-lg font-bold tracking-tight text-ink">{{ __('Klienterna i siffror') }}</span>
            <span class="text-sm font-semibold text-accent-700">{{ $showFigures ? __('Dölj') : __('Visa') }}</span>
        </button>
        @if($showFigures)
            <div class="overflow-x-auto border-t border-stone-200">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <th scope="col" class="px-5 py-3 sm:px-6">{{ __('Företag') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __('Ansvarig') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ __('Bank och kassa') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ __('Kundfordringar') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ __('Leverantörsskulder') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ __('Moms') }}</th>
                            <th scope="col" class="px-5 py-3 text-right sm:px-6">{{ __('Att göra') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        @foreach($data['companies'] as $row)
                            <tr wire:key="figures-{{ $row['id'] }}">
                                <th scope="row" class="px-5 py-3 text-left font-medium text-ink sm:px-6">{{ $row['name'] }}</th>
                                <td class="px-4 py-3 text-gray-600">{{ $row['responsible_name'] ?? '–' }}</td>
                                @foreach(['bank', 'receivables', 'payables', 'vat'] as $figure)
                                    <td class="px-4 py-3 text-right">
                                        @if($row[$figure] === null)
                                            <span class="text-gray-400" title="{{ __('Din roll i företaget visar inte den här siffran.') }}">–</span>
                                        @else
                                            <x-ui.amount :money="$money($row[$figure])" />
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-5 py-3 text-right tabular-nums sm:px-6">{{ $row['open_items'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
