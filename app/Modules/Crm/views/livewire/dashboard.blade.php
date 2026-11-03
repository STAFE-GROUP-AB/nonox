@php
    $chip = 'inline-flex min-h-11 items-center rounded-xl px-4 text-sm font-medium';
    $money = fn (int $ore) => App\Support\Money\Money::ofOre($ore);
    $tile = 'block rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200';
    $maxWeek = max(1, max(array_column($weeks, 'count')));
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Vems siffror') }}">
            <button type="button" wire:click="$set('who', '')" @class([$chip, 'bg-ink text-white' => $who === '', 'bg-white text-ink ring-1 ring-stone-200' => $who !== '']) aria-pressed="{{ $who === '' ? 'true' : 'false' }}">{{ __('Hela företaget') }}</button>
            <button type="button" wire:click="$set('who', 'mina')" @class([$chip, 'bg-ink text-white' => $who === 'mina', 'bg-white text-ink ring-1 ring-stone-200' => $who !== 'mina']) aria-pressed="{{ $who === 'mina' ? 'true' : 'false' }}">{{ __('Mina') }}</button>
        </div>
        <label class="ms-auto flex items-center gap-2 text-gray-600">
            <span>{{ __('Period') }}</span>
            <select class="min-h-11 rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500" wire:model.live="period">
                @foreach(App\Modules\Crm\Livewire\Dashboard::PERIODS as $option)
                    <option value="{{ $option }}">{{ __('Senaste :days dagarna', ['days' => $option]) }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="{{ __('Nyckeltal för försäljningen') }}" data-testid="crm-dashboard">
        <a href="{{ route('crm.deals', $who === 'mina' ? ['ansvarig' => 'mina'] : []) }}" class="block rounded-3xl bg-ink px-5 py-4 text-white shadow-xs hover:bg-black">
            <span class="block text-sm text-stone-300">{{ __('Öppna affärer') }} ({{ $open['count'] }})</span>
            <x-ui.amount :money="$money($open['value'])" :on-dark="true" class="mt-1 block text-2xl font-bold tracking-tight text-white" />
            <span class="mt-2 inline-flex rounded-full bg-white/10 px-2.5 py-1 text-xs text-stone-200">{{ __('Viktat') }} {{ $money($open['weighted'])->format() }}</span>
        </a>
        <div class="{{ $tile }}">
            <span class="block text-sm text-gray-500">{{ __('Andel vunna') }}</span>
            <span class="mt-1 block text-2xl font-bold tracking-tight text-ink tabular-nums">{{ $winRate['rate'] === null ? '–' : number_format($winRate['rate'] * 100, 0, ',', ' ').' %' }}</span>
            <span class="mt-2 block text-xs text-gray-500">{{ __(':won vunna och :lost förlorade, :days dagar', ['won' => $winRate['won'], 'lost' => $winRate['lost'], 'days' => $days]) }}</span>
        </div>
        <div class="{{ $tile }}">
            <span class="block text-sm text-gray-500">{{ __('Säljcykel i snitt') }}</span>
            <span class="mt-1 block text-2xl font-bold tracking-tight text-ink tabular-nums">{{ $cycle === null ? '–' : __(':days dagar', ['days' => number_format($cycle, 1, ',', ' ')]) }}</span>
            <span class="mt-2 block text-xs text-gray-500">{{ __('Från skapad till vunnen, för affärer vunna de senaste :days dagarna', ['days' => $days]) }}</span>
        </div>
        <a href="{{ route('crm.deals', array_filter(['visa' => 'utan-steg', 'ansvarig' => $who === 'mina' ? 'mina' : null])) }}" @class(['block rounded-3xl px-5 py-4 shadow-xs', 'bg-accent-50 ring-1 ring-accent-200 hover:ring-accent-300' => $missing > 0, 'bg-white ring-1 ring-stone-200 hover:ring-stone-300' => $missing === 0])>
            <span class="block text-sm text-gray-500">{{ __('Utan nästa steg') }}</span>
            <span @class(['mt-1 block text-2xl font-bold tracking-tight tabular-nums', 'text-accent-800' => $missing > 0, 'text-ink' => $missing === 0])>{{ $missing }}</span>
            <span class="mt-2 block text-xs text-gray-500">{{ $missing > 0 ? __('Öppna affärer som ingen har planerat något för.') : __('Alla öppna affärer har ett nästa steg.') }}</span>
        </a>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Aktiviteter per vecka')" :description="__('Klara samtal, möten, e-post, uppgifter och anteckningar, de senaste åtta veckorna.')">
            <ol class="space-y-2" data-testid="crm-weeks">
                @foreach($weeks as $week)
                    <li class="flex items-center gap-3 text-sm">
                        <span class="w-20 flex-none text-gray-600">{{ __('v. :week', ['week' => (int) substr($week['week'], -2)]) }}</span>
                        <span class="h-3 flex-1 overflow-hidden rounded-full bg-stone-100" aria-hidden="true">
                            <span class="block h-full rounded-full bg-accent-500" style="width: {{ round($week['count'] / $maxWeek * 100, 1) }}%"></span>
                        </span>
                        <span class="w-10 flex-none text-right font-medium tabular-nums text-ink">{{ $week['count'] }}</span>
                    </li>
                @endforeach
            </ol>
        </x-ui.card>

        <x-ui.card :title="__('Mål den här månaden')">
            <x-slot:action>
                <a href="{{ route('crm.forecast') }}" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Prognos och mål') }}</a>
            </x-slot:action>
            @if($goals === [])
                <p class="text-sm text-gray-600">{{ $allGoals ? __('Inga mål för månaden. Sätt mål per säljare under Prognos och mål.') : __('Du har inga mål för månaden.') }}</p>
            @else
                @include('crm::livewire.partials.goal-progress', ['goals' => $goals, 'showNames' => $allGoals])
            @endif
        </x-ui.card>
    </div>
</div>
