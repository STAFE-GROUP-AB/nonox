{{--
    Deals in a side card or a tab: the open ones with stage, value and the
    date of the next step (in the accent when it is overdue or missing),
    and the won and lost ones folded away under a disclosure. Each deal
    needs next_due_at and open_steps (withMin / withCount) and its stage.
--}}
@props(['deals', 'now', 'empty' => null])

@php
    $open = $deals->filter(fn ($deal) => $deal->isOpen());
    $closed = $deals->reject(fn ($deal) => $deal->isOpen());
    $money = fn (int $ore) => App\Support\Money\Money::ofOre($ore);
@endphp

<div {{ $attributes }}>
    @if($deals->isEmpty())
        <p class="text-sm text-gray-600">{{ $empty ?? __('Inga affärer ännu.') }}</p>
    @endif

    @if($open->isNotEmpty())
        <ul class="space-y-3" data-testid="crm-open-deals">
            @foreach($open as $deal)
                @php
                    $nextDue = $deal->next_due_at === null ? null : \Carbon\CarbonImmutable::parse($deal->next_due_at);
                    $missing = (int) $deal->open_steps === 0;
                    $late = $nextDue !== null && $nextDue->lessThan($now);
                @endphp
                <li wire:key="deal-row-{{ $deal->id }}">
                    <a href="{{ route('crm.deals.show', $deal) }}" class="block rounded-2xl bg-stone-50 p-4 ring-1 ring-stone-200 hover:bg-white hover:ring-stone-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-600 motion-safe:transition-colors">
                        <span class="block font-semibold leading-snug text-ink">{{ $deal->title }}</span>
                        <span class="mt-1 flex items-center justify-between gap-3">
                            <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-stone-200">{{ $deal->stage?->name }}</span>
                            <x-ui.amount :money="$money($deal->value_ore)" class="text-sm font-semibold text-ink" />
                        </span>
                        <span class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                            <span @class(['font-medium text-accent-700' => $missing || $late, 'text-gray-600' => ! $missing && ! $late])>
                                @if($missing)
                                    {{ __('Nästa steg saknas') }}
                                @elseif($nextDue)
                                    {{ __('Nästa steg') }} {{ $nextDue->setTimezone('Europe/Stockholm')->translatedFormat('j M') }}@if($late) · {{ __('försenat') }}@endif
                                @else
                                    {{ __('Nästa steg utan datum') }}
                                @endif
                            </span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    @if($closed->isNotEmpty())
        <details class="group mt-4">
            <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 text-sm font-medium text-gray-700 hover:text-ink">
                <svg class="size-4 motion-safe:transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                {{ __('Avslutade (:count)', ['count' => $closed->count()]) }}
            </summary>
            <ul class="mt-2 divide-y divide-stone-100">
                @foreach($closed as $deal)
                    <li wire:key="deal-closed-{{ $deal->id }}" class="flex items-baseline justify-between gap-3 py-2 text-sm">
                        <a href="{{ route('crm.deals.show', $deal) }}" class="min-w-0 truncate text-ink hover:text-accent-700">{{ $deal->title }}</a>
                        <span class="flex flex-none items-center gap-2">
                            <x-ui.badge :tone="$deal->status->tone()">{{ $deal->status->label() }}</x-ui.badge>
                            <x-ui.amount :money="$money($deal->value_ore)" class="text-gray-700" />
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
