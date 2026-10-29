@props(['title', 'action' => null])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-dashed border-gray-300 px-6 py-10 text-center']) }}>
    <p class="font-medium text-gray-900">{{ $title }}</p>
    <p class="mt-1 text-sm text-gray-600">{{ $slot }}</p>
    @if($action)
        <div class="mt-4">{{ $action }}</div>
    @endif
</div>
