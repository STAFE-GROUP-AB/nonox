@php
    $long = [1 => __('januari'), __('februari'), __('mars'), __('april'), __('maj'), __('juni'), __('juli'), __('augusti'), __('september'), __('oktober'), __('november'), __('december')];
    $monthName = fn (string $month): string => $long[(int) substr($month, 5, 2)].' '.substr($month, 0, 4);
    $states = [
        'done' => [__('Klar'), 'bg-green-50 text-green-800 ring-green-200'],
        'todo' => [__('Att göra'), 'bg-accent-50 text-accent-800 ring-accent-200'],
        'late' => [__('Försenad'), 'bg-accent-600 text-white ring-accent-600'],
        'open' => [__('Pågår'), 'bg-stone-100 text-gray-700 ring-stone-200'],
        'na' => [__('Ej aktuell'), 'bg-white text-gray-400 ring-stone-200'],
    ];
    $select = 'block w-full rounded-xl border-gray-300 py-2 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500';
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="close_month" class="text-xs font-medium text-gray-500">{{ __('Månad') }}</label>
                <select id="close_month" class="{{ $select }} mt-1" wire:model.live="month">
                    @foreach($months as $option)
                        <option value="{{ $option }}" @selected($option === $selected)>{{ ucfirst($monthName($option)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="close_responsible" class="text-xs font-medium text-gray-500">{{ __('Ansvarig konsult') }}</label>
                <select id="close_responsible" class="{{ $select }} mt-1" wire:model.live="responsible">
                    <option value="">{{ __('Alla') }}</option>
                    <option value="ingen">{{ __('Ingen ansvarig') }}</option>
                    @foreach($consultants as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="flex items-center gap-3 text-sm text-gray-600">
            <span>{{ __(':complete av :total klienter klara med :month', ['complete' => $complete, 'total' => count($rows), 'month' => $monthName($selected)]) }}</span>
            <button type="button" wire:click="reload" class="rounded-xl bg-white px-3 py-2 font-semibold text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300">{{ __('Läs in på nytt') }}</button>
        </div>
    </div>

    <p class="text-sm text-gray-600">{{ __('Gröna steg är klara. Stegen fylls i av sig själva där bokföringen visar det; övriga bockar du av för hand. Klicka på ett steg för att bocka av det eller ångra.') }}</p>

    @if($rows === [])
        <x-ui.card>
            <x-ui.empty-state :title="__('Inga klienter att visa')">{{ __('Månadsstängningen visar de företag där du kan se uppgifter.') }}</x-ui.empty-state>
        </x-ui.card>
    @else
        {{-- Phones: one card per client. --}}
        <ul class="space-y-3 md:hidden">
            @foreach($rows as $row)
                <li wire:key="close-card-{{ $row['company_id'] }}" class="rounded-3xl bg-white p-4 shadow-xs ring-1 ring-stone-200">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="font-semibold text-ink">{{ $row['company_name'] }}</p>
                        <p class="text-sm tabular-nums text-gray-600">{{ $row['done'] }}/{{ $row['total'] }}</p>
                    </div>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach($steps as $step)
                            @php($cell = $row['cells'][$step->value])
                            <li>@include('livewire.firm.month-close-cell')</li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>

        <section class="hidden overflow-hidden rounded-3xl bg-white shadow-xs ring-1 ring-stone-200 md:block">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <th scope="col" class="sticky left-0 min-w-52 bg-white px-5 py-3">{{ __('Klient') }}</th>
                            @foreach($steps as $step)
                                <th scope="col" class="px-3 py-3" title="{{ $step->hint() }}">{{ $step->label() }}</th>
                            @endforeach
                            <th scope="col" class="min-w-28 px-5 py-3 text-right">{{ __('Klart') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        @foreach($rows as $row)
                            <tr wire:key="close-row-{{ $row['company_id'] }}">
                                <th scope="row" class="sticky left-0 bg-white px-5 py-3 text-left font-medium text-ink">
                                    {{ $row['company_name'] }}
                                    @if($row['responsible_name'])
                                        <span class="block text-xs font-normal text-gray-500">{{ $row['responsible_name'] }}</span>
                                    @endif
                                </th>
                                @foreach($steps as $step)
                                    @php($cell = $row['cells'][$step->value])
                                    <td class="px-3 py-3">@include('livewire.firm.month-close-cell')</td>
                                @endforeach
                                <td class="px-5 py-3 text-right">
                                    <span class="tabular-nums font-semibold text-ink">{{ $row['done'] }}/{{ $row['total'] }}</span>
                                    <span class="mt-1 block h-1.5 w-20 overflow-hidden rounded-full bg-stone-200 ms-auto" aria-hidden="true">
                                        <span class="block h-full rounded-full bg-green-600" style="width: {{ $row['total'] === 0 ? 100 : (int) round($row['done'] / $row['total'] * 100) }}%"></span>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
