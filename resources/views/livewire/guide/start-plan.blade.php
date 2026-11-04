{{-- Min startplan (App\Livewire\Guide\StartPlan): progress, then the steps with a checkbox each. --}}
@php
    $total = count($steps);
    $percent = $total === 0 ? 0 : (int) round($done / $total * 100);
@endphp

<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-lg font-bold tracking-tight text-ink" data-guide-progress>{{ __(':done av :total klara', ['done' => $done, 'total' => $total]) }}</p>
            <a href="{{ route('guide.home') }}" class="text-sm font-medium text-accent-700 underline hover:text-accent-800">{{ __('Hela Företagsguiden') }}</a>
        </div>
        <div class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-stone-200" role="progressbar" aria-label="{{ __('Startplanen') }}" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $done }}" aria-valuetext="{{ __(':done av :total klara', ['done' => $done, 'total' => $total]) }}">
            <div class="h-full rounded-full bg-accent-600" style="width: {{ $percent }}%"></div>
        </div>
        <p class="mt-3 text-sm text-gray-600">{{ __('Företagsguiden är i beta. Texterna är kontrollerade mot källorna men ännu inte granskade av en redovisningskonsult.') }}</p>
        @if($tipsHidden)
            <form method="POST" action="{{ route('guide.tips') }}" class="mt-2">
                @csrf
                @method('PUT')
                <input type="hidden" name="visible" value="1">
                <button type="submit" class="text-sm font-medium text-accent-700 underline hover:text-accent-800">{{ __('Visa tipsen igen') }}</button>
            </form>
        @endif
        @unless($canTick)
            <p class="mt-2 text-sm text-gray-600">{{ __('Du kan se startplanen, men bara ägare och administratörer kan markera steg som klara.') }}</p>
        @endunless
    </x-ui.card>

    @if($steps === [])
        <x-ui.empty-state :title="__('Startplanen har inga steg ännu')">
            {{ __('Stegen skrivs just nu. Läs gärna Företagsguiden under tiden.') }}
        </x-ui.empty-state>
    @else
        <ol class="space-y-3">
            @foreach($steps as $index => $step)
                @php
                    $record = $progress->get($step->key);
                    $articleUrl = $step->articleUrl();
                    $routeUrl = $step->routeUrl();
                @endphp
                <li wire:key="guide-step-{{ $step->key }}" @class(['rounded-2xl bg-white p-5 ring-1 ring-stone-200', 'opacity-80' => $record]) data-guide-step="{{ $step->key }}">
                    <div class="flex items-start gap-4">
                        <input id="guide-step-{{ $step->key }}" type="checkbox" class="mt-1 size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500 disabled:opacity-60"
                               @checked($record) @disabled(! $canTick)
                               wire:click="toggle('{{ $step->key }}', $event.target.checked)" wire:loading.attr="disabled">
                        <div class="min-w-0 flex-1">
                            <label for="guide-step-{{ $step->key }}" @class(['block font-semibold text-ink', 'line-through decoration-stone-400' => $record])>
                                <span class="text-gray-500 tabular-nums">{{ $index + 1 }}.</span> {{ $step->title }}
                            </label>
                            <p class="mt-1 text-sm text-gray-700">{{ $step->text }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                @if($articleUrl)
                                    <a href="{{ $articleUrl }}" class="font-medium text-accent-700 underline hover:text-accent-800">{{ __('Läs mer') }}<span class="sr-only">: {{ $step->title }}</span></a>
                                @endif
                                @if($routeUrl)
                                    <a href="{{ $routeUrl }}" class="font-medium text-accent-700 underline hover:text-accent-800">{{ __('Gör det i NONOX') }}<span class="sr-only">: {{ $step->title }}</span></a>
                                @endif
                                @if($record)
                                    <span class="text-gray-500">{{ $record->completer ? __('Klart :date av :name', ['date' => $record->completed_at->format('Y-m-d'), 'name' => $record->completer->name]) : __('Klart :date', ['date' => $record->completed_at->format('Y-m-d')]) }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
