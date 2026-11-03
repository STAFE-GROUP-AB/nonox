{{--
    A path of connected steps: done (tinted, with a check), current (the
    accent, aria-current="step") and later (muted). An ordered list, so a
    screen reader hears "3 of 5". It scrolls sideways on a phone with the
    current step brought into view. steps: list of {label, state, meta?}.
--}}
@props(['steps', 'label'])

@php
    $notch = '0.75rem';
    $count = count($steps);
@endphp

<div {{ $attributes->merge(['class' => '-mx-1 overflow-x-auto px-1 pb-1']) }} x-data x-init="$nextTick(() => $el.querySelector('[aria-current=step]')?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'instant' }))">
    <ol class="flex min-w-max sm:min-w-0" aria-label="{{ $label }}">
        @foreach($steps as $i => $step)
            @php
                $first = $i === 0;
                $last = $i === $count - 1;
                $shape = match (true) {
                    $first && $last => 'none',
                    $first => "polygon(0 0, calc(100% - {$notch}) 0, 100% 50%, calc(100% - {$notch}) 100%, 0 100%)",
                    $last => "polygon(0 0, 100% 0, 100% 100%, 0 100%, {$notch} 50%)",
                    default => "polygon(0 0, calc(100% - {$notch}) 0, 100% 50%, calc(100% - {$notch}) 100%, 0 100%, {$notch} 50%)",
                };
            @endphp
            <li @class(['relative min-w-[8.5rem] flex-1 sm:min-w-0', '-ms-1.5' => ! $first]) @if($step['state'] === 'current') aria-current="step" @endif>
                <div @class([
                    'flex h-12 items-center gap-1.5 text-sm font-semibold motion-safe:transition-colors',
                    'rounded-s-xl' => $first,
                    'rounded-e-xl' => $last,
                    'ps-4' => $first,
                    'ps-6' => ! $first,
                    'pe-5',
                    'bg-accent-100 text-accent-900' => $step['state'] === 'done',
                    'bg-accent-600 text-white shadow-xs' => $step['state'] === 'current',
                    'bg-stone-100 text-gray-600' => $step['state'] === 'todo',
                ]) style="clip-path: {{ $shape }}">
                    @if($step['state'] === 'done')
                        <x-crm::icon name="check" class="size-4 flex-none" />
                        <span class="sr-only">{{ __('Klart:') }}</span>
                    @endif
                    <span class="truncate">{{ $step['label'] }}</span>
                </div>
                @if(! empty($step['meta']))
                    <p @class(['mt-1.5 truncate text-xs', 'ps-4' => $first, 'ps-6' => ! $first, 'font-medium text-accent-800' => $step['state'] === 'current', 'text-gray-500' => $step['state'] !== 'current'])>{{ $step['meta'] }}</p>
                @endif
            </li>
        @endforeach
    </ol>
</div>
