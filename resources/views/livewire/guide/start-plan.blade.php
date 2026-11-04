{{--
    Min startplan (App\Livewire\Guide\StartPlan): a progress ring with the
    next step, then the steps in their groups (StepGroups), each with a
    checkbox for those who may tick it.
--}}
@php
    $total = count($steps);
    $percent = $total === 0 ? 0 : (int) round($done / $total * 100);
    $circumference = 2 * M_PI * 52;
    $groups = App\Support\Guide\StepGroups::group($steps);
    $next = collect($groups)->flatMap(fn (array $group): array => $group['steps'])->first(fn (array $item): bool => ! $progress->has($item['step']->key))['step'] ?? null;
@endphp

<div class="space-y-6">
    <section class="rounded-3xl bg-white p-6 shadow-xs ring-1 ring-stone-200 sm:p-8" aria-labelledby="guide-progress-title">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-center">
            <div class="relative size-32 shrink-0" role="progressbar" aria-label="{{ __('Startplanen') }}" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $done }}" aria-valuetext="{{ __(':done av :total klara', ['done' => $done, 'total' => $total]) }}">
                <svg viewBox="0 0 120 120" class="size-32 -rotate-90" aria-hidden="true">
                    <circle cx="60" cy="60" r="52" fill="none" stroke-width="12" class="stroke-stone-200" />
                    <circle cx="60" cy="60" r="52" fill="none" stroke-width="12" stroke-linecap="round" class="stroke-accent-500 transition-[stroke-dashoffset] duration-500 motion-reduce:transition-none"
                            stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $circumference * (1 - $percent / 100) }}" />
                </svg>
                <span class="absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-3xl font-bold tracking-tight text-ink tabular-nums">{{ $percent }}&nbsp;%</span>
                </span>
            </div>
            <div class="min-w-0 flex-1">
                <h2 id="guide-progress-title" class="text-2xl font-bold tracking-tight text-ink tabular-nums" data-guide-progress>{{ __(':done av :total klara', ['done' => $done, 'total' => $total]) }}</h2>
                @if($next)
                    <p class="mt-2 text-sm text-gray-600">{{ __('Nästa steg') }}</p>
                    <p class="mt-0.5 text-base font-semibold text-ink">{{ $next->title }}</p>
                @elseif($total > 0)
                    <p class="mt-2 text-base font-semibold text-emerald-800">{{ __('Alla steg är klara.') }}</p>
                @endif
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                    @if($next?->articleUrl())
                        <a href="{{ $next->articleUrl() }}" class="inline-flex min-h-10 items-center rounded-full bg-ink px-4 font-semibold text-white hover:bg-accent-600">{{ __('Läs om nästa steg') }}</a>
                    @endif
                    <a href="{{ route('guide.home') }}" class="font-medium text-accent-700 underline hover:text-accent-800">{{ __('Hela Företagsguiden') }}</a>
                    @if($tipsHidden)
                        <form method="POST" action="{{ route('guide.tips') }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="visible" value="1">
                            <button type="submit" class="font-medium text-accent-700 underline hover:text-accent-800">{{ __('Visa tipsen igen') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
        <p class="mt-6 rounded-xl bg-stone-100 px-4 py-3 text-sm text-gray-700">{{ __('Företagsguiden är i beta. Texterna är kontrollerade mot källorna men ännu inte granskade av en redovisningskonsult.') }}</p>
        @unless($canTick)
            <p class="mt-3 text-sm text-gray-600">{{ __('Du kan se startplanen, men bara ägare och administratörer kan markera steg som klara.') }}</p>
        @endunless
    </section>

    @if($steps === [])
        <x-ui.empty-state :title="__('Startplanen har inga steg ännu')">
            {{ __('Stegen skrivs just nu. Läs gärna Företagsguiden under tiden.') }}
        </x-ui.empty-state>
    @else
        <div class="grid gap-6 xl:grid-cols-2">
            @foreach($groups as $group)
                @php $groupDone = collect($group['steps'])->filter(fn (array $item): bool => $progress->has($item['step']->key))->count(); @endphp
                <section class="rounded-3xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6" aria-labelledby="guide-group-{{ $group['key'] }}" data-guide-group="{{ $group['key'] }}">
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 id="guide-group-{{ $group['key'] }}" class="text-lg font-bold tracking-tight text-ink">{{ $group['label'] }}</h2>
                        <span @class(['rounded-full px-2.5 py-0.5 text-xs font-semibold tabular-nums', 'bg-emerald-100 text-emerald-900' => $groupDone === count($group['steps']), 'bg-stone-100 text-gray-700' => $groupDone !== count($group['steps'])])>{{ __(':done av :total', ['done' => $groupDone, 'total' => count($group['steps'])]) }}</span>
                    </div>
                    <ol class="mt-3 divide-y divide-stone-100">
                        @foreach($group['steps'] as $item)
                            @php
                                $step = $item['step'];
                                $record = $progress->get($step->key);
                                $articleUrl = $step->articleUrl();
                                $routeUrl = $step->routeUrl();
                            @endphp
                            <li wire:key="guide-step-{{ $step->key }}" @class(['flex items-start gap-4 py-4', '-mx-3 rounded-2xl bg-accent-50 px-3 ring-1 ring-accent-200' => $next?->key === $step->key]) data-guide-step="{{ $step->key }}" @if($next?->key === $step->key) aria-current="step" @endif>
                                <input id="guide-step-{{ $step->key }}" type="checkbox" class="mt-0.5 size-6 shrink-0 rounded-md border-stone-300 text-accent-600 focus:ring-accent-500 disabled:opacity-60"
                                       @checked($record) @disabled(! $canTick)
                                       wire:click="toggle('{{ $step->key }}', $event.target.checked)" wire:loading.attr="disabled">
                                <div class="min-w-0 flex-1">
                                    <label for="guide-step-{{ $step->key }}" @class(['block font-semibold leading-snug', 'text-gray-500 line-through decoration-stone-400' => $record, 'text-ink' => ! $record])>
                                        <span class="text-gray-500 tabular-nums">{{ $item['number'] }}.</span> {{ $step->title }}
                                    </label>
                                    @unless($record)
                                        <p class="mt-1 text-sm leading-relaxed text-gray-700">{{ $step->text }}</p>
                                    @endunless
                                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                        @if($articleUrl)
                                            <a href="{{ $articleUrl }}" class="font-medium text-accent-700 underline hover:text-accent-800">{{ __('Läs mer') }}<span class="sr-only">: {{ $step->title }}</span></a>
                                        @endif
                                        @if($routeUrl)
                                            <a href="{{ $routeUrl }}" class="font-medium text-accent-700 underline hover:text-accent-800">{{ __('Gör det i NONOX') }}<span class="sr-only">: {{ $step->title }}</span></a>
                                        @endif
                                        @if($record)
                                            <span class="inline-flex items-center gap-1 text-emerald-800">
                                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10" /></svg>
                                                {{ $record->completer ? __('Klart :date av :name', ['date' => $record->completed_at->format('Y-m-d'), 'name' => $record->completer->name]) : __('Klart :date', ['date' => $record->completed_at->format('Y-m-d')]) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endforeach
        </div>
    @endif
</div>
