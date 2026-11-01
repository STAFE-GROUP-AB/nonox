{{--
    The title of a page: compact, so the page's content starts high. Where to
    go next is in the sidebar, not here. An optional action sits to the right.
--}}
@props(['title', 'lead' => null, 'action' => null])

<div {{ $attributes->class(['flex flex-wrap items-start justify-between gap-x-6 gap-y-3']) }}>
    <div class="min-w-0">
        <h1 class="text-2xl font-bold tracking-tight text-ink">{{ $title }}</h1>
        @if($lead)
            <p class="mt-1 max-w-4xl text-sm text-gray-600">{{ $lead }}</p>
        @endif
    </div>
    @if($action)
        <div class="shrink-0">{{ $action }}</div>
    @endif
</div>
