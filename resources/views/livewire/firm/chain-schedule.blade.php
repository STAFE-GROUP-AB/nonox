@php
    use App\Modules\Planning\Domain\WorkingTimeChecks;
    use App\Support\Money\Money;

    $locale = app()->getLocale();
    $hours = fn (int $minutes): string => WorkingTimeChecks::hours($minutes);
    $money = fn (int $ore): string => Money::ofOre($ore)->format();
    $totals = $data['totals'];
    $weekNumber = (int) $monday->format('W');
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="previous" class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300" aria-label="{{ __('Föregående vecka') }}">‹</button>
            <button type="button" wire:click="today" class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300">{{ __('Idag') }}</button>
            <button type="button" wire:click="next" class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300" aria-label="{{ __('Nästa vecka') }}">›</button>
            <h2 class="ms-2 text-lg font-bold tracking-tight text-ink">{{ __('Vecka :week', ['week' => $weekNumber]) }} <span class="text-sm font-normal text-gray-500">{{ $monday->locale($locale)->isoFormat('D MMM') }}–{{ $monday->addDays(6)->locale($locale)->isoFormat('D MMM YYYY') }}</span></h2>
        </div>
        <p class="text-sm text-gray-500">{{ __('Bara att läsa. Ett pass ändrar du i sitt eget företag.') }}</p>
    </div>

    @if($data['eligible'] === [])
        <x-ui.card>
            <x-ui.empty-state :title="__('Inget schema att visa')">{{ __('Här syns schemat för de företag där du har rätt att se det och som har Planering påslaget. Slå på Planering under Moduler i ett av dina företag.') }}</x-ui.empty-state>
        </x-ui.card>
    @else
        <section class="rounded-3xl bg-white p-5 shadow-xs ring-1 ring-stone-200" aria-labelledby="chain-choice">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 id="chain-choice" class="text-sm font-semibold text-ink">{{ __('Företag i schemat') }}</h3>
                @if($chosen !== [])
                    <button type="button" wire:click="clearChoice" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Visa alla') }}</button>
                @endif
            </div>
            <ul class="mt-3 flex flex-wrap gap-x-6 gap-y-2">
                @foreach($data['eligible'] as $id => $name)
                    <li wire:key="chain-company-{{ $id }}">
                        <label class="flex items-center gap-2 text-sm text-gray-800">
                            <input type="checkbox" value="{{ $id }}" wire:model.live="chosen" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500">
                            {{ $name }}
                        </label>
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-gray-500">{{ __('Inget valt visar alla företag där du kan se schemat.') }}</p>

            <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-stone-200 pt-4">
                <div>
                    <label for="chain_view_name" class="text-xs font-medium text-gray-500">{{ __('Spara urvalet som vy') }}</label>
                    <input id="chain_view_name" type="text" wire:model="viewName" wire:keydown.enter="saveView" maxlength="60" placeholder="{{ __('Namn på vyn') }}" class="mt-1 block rounded-xl border-gray-300 py-2 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500">
                    @error('viewName')<p class="mt-1 text-xs text-red-700" role="alert">{{ $message }}</p>@enderror
                </div>
                <button type="button" wire:click="saveView" class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300">{{ __('Spara vy') }}</button>
                @if($views->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-gray-500">{{ __('Sparade vyer:') }}</span>
                        @foreach($views as $view)
                            <span class="inline-flex items-center rounded-full bg-stone-100 ring-1 ring-stone-200" wire:key="chain-view-{{ $view->id }}">
                                <button type="button" class="px-3 py-1 font-medium text-gray-800 hover:text-ink" wire:click="applyView({{ $view->id }})">{{ $view->name }}</button>
                                <button type="button" class="pe-2 text-gray-400 hover:text-accent-700" wire:click="deleteView({{ $view->id }})" aria-label="{{ __('Ta bort vyn :name', ['name' => $view->name]) }}">×</button>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Summor för valda företag') }}">
            <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
                <span class="block text-sm text-gray-500">{{ __('Pass') }}</span>
                <span class="mt-1 block text-2xl font-bold tabular-nums tracking-tight text-ink">{{ $totals['shifts'] }}</span>
                <span class="mt-2 block text-xs text-gray-500">{{ __(':count öppna', ['count' => $totals['open']]) }}</span>
            </div>
            <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
                <span class="block text-sm text-gray-500">{{ __('Planerad tid') }}</span>
                <span class="mt-1 block text-2xl font-bold tabular-nums tracking-tight text-ink">{{ $hours($totals['minutes']) }}</span>
                <span class="mt-2 block text-xs text-gray-500">{{ __(':count företag', ['count' => count($data['companies'])]) }}</span>
            </div>
            <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
                <span class="block text-sm text-gray-500">{{ __('Uppskattad kostnad') }}</span>
                <span class="mt-1 block text-2xl font-bold tabular-nums tracking-tight text-ink">{{ $totals['cost'] > 0 ? $money($totals['cost']) : '–' }}@if($totals['incomplete']) <span title="{{ __('Någon kostnad saknas') }}">*</span>@endif</span>
                <span class="mt-2 block text-xs text-gray-500">{{ __('Uppskattning från passen, inte lön') }}</span>
            </div>
            <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
                <span class="block text-sm text-gray-500">{{ __('Krockar mellan företag') }}</span>
                <span class="mt-1 block text-2xl font-bold tabular-nums tracking-tight text-ink">{{ count($data['conflicts']) }}</span>
                <span class="mt-2 block text-xs text-gray-500">{{ __('Samma inloggning i två företag') }}</span>
            </div>
        </section>

        @if($data['conflicts'] !== [])
            <section class="rounded-3xl bg-amber-50 p-5 ring-1 ring-amber-200" aria-labelledby="chain-conflicts">
                <h3 id="chain-conflicts" class="text-sm font-semibold text-amber-900">{{ __('Krockar mellan företag') }}</h3>
                <ul class="mt-2 space-y-1 text-sm text-amber-900">
                    @foreach($data['conflicts'] as $conflict)
                        <li wire:key="conflict-{{ $conflict['key'] }}">{{ \Carbon\CarbonImmutable::parse($conflict['date'])->locale($locale)->isoFormat('ddd D/M') }}: {{ $conflict['message'] }}</li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-amber-900">{{ __('Det här är en varning, inget hindrar planeringen. Ändra passet i sitt företag.') }}</p>
            </section>
        @endif

        <div class="space-y-4">
            @foreach($data['companies'] as $block)
                <section class="overflow-hidden rounded-3xl bg-white shadow-xs ring-1 ring-stone-200" wire:key="chain-block-{{ $block['id'] }}" aria-label="{{ $block['name'] }}">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-5 py-3">
                        <div>
                            <h3 class="font-bold tracking-tight text-ink">{{ $block['name'] }}</h3>
                            <p class="text-xs text-gray-600">
                                {{ __(':shifts pass', ['shifts' => $block['shifts']]) }} · {{ __(':open öppna', ['open' => $block['open']]) }} · {{ $hours($block['minutes']) }}
                                @if($block['cost'] > 0) · {{ $money($block['cost']) }}@if($block['incomplete']) *@endif @endif
                                @if($block['drafts'] > 0) · {{ __(':count utkast', ['count' => $block['drafts']]) }}@endif
                            </p>
                        </div>
                        @include('firm.open-in-company', [
                            'companyId' => $block['id'],
                            'destination' => 'planning',
                            'week' => $monday->toDateString(),
                            'label' => __('Öppna schemat i företaget'),
                            'class' => 'rounded-xl bg-paper px-4 py-2 text-sm font-semibold text-accent-700 ring-1 ring-stone-200 hover:ring-stone-300',
                        ])
                    </header>
                    <div class="overflow-x-auto">
                        <div class="grid min-w-[56rem]" style="grid-template-columns: repeat(7, minmax(0, 1fr));">
                            @foreach($data['days'] as $day)
                                <div @class(['border-l border-stone-200 first:border-l-0 p-2', 'bg-accent-50/30' => $day->isToday()])>
                                    <p class="text-xs font-semibold text-gray-600">{{ ucfirst($day->locale($locale)->isoFormat('ddd D/M')) }}</p>
                                    <ul class="mt-2 space-y-1.5">
                                        @foreach($block['chips'][$day->toDateString()] ?? [] as $chip)
                                            <li wire:key="chip-{{ $chip['id'] }}">
                                                @include('firm.open-in-company', [
                                                    'companyId' => $block['id'],
                                                    'destination' => 'planning',
                                                    'week' => $monday->toDateString(),
                                                    'day' => $chip['date'],
                                                    'label' => $chip['start'].'–'.$chip['end'].' '.($chip['person'] ?? __('Öppet pass')).($chip['area'] ? ' · '.$chip['area'] : '').($chip['draft'] ? ' ('.__('utkast').')' : '').($chip['warnings'] !== [] ? ' ⚠' : ''),
                                                    'title' => implode(' ', $chip['warnings']),
                                                    'class' => 'block w-full rounded-lg px-2 py-1 text-left text-xs font-medium text-gray-800 ring-1 '.($chip['warnings'] !== [] ? 'bg-amber-50 ring-amber-300' : ($chip['open'] ? 'bg-stone-50 ring-stone-300' : 'bg-accent-50 ring-accent-200')).($chip['draft'] ? ' border border-dashed border-gray-400' : ''),
                                                ])
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
