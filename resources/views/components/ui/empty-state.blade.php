@props(['title', 'action' => null])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-dashed border-stone-300 bg-paper/60 px-6 py-10 text-center']) }}>
    <p class="font-semibold text-ink">{{ $title }}</p>
    <p class="mt-1 text-sm text-gray-600">{{ $slot }}</p>
    @if($action)
        <div class="mt-4">{{ $action }}</div>
    @endif
</div>
