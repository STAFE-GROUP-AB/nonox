<div class="grid gap-6 xl:grid-cols-2" role="status">
    <span class="sr-only">{{ __('Hämtar statistiken …') }}</span>
    @foreach(range(1, 4) as $i)
        <div class="h-80 animate-pulse rounded-3xl bg-white ring-1 ring-stone-200" aria-hidden="true"></div>
    @endforeach
</div>
