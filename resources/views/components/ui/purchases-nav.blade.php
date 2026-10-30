<div class="space-y-6">
    <nav class="flex flex-wrap gap-2 print:hidden" aria-label="{{ __('Inköp') }}">
        @foreach(['payables.invoices.index' => __('Leverantörsfakturor'), 'payables.batches.index' => __('Betalfiler')] as $route => $label)
            @php($active = request()->routeIs(str_replace('.index', '.*', $route)))
            <a href="{{ route($route) }}" @class([
                'rounded-full px-4 py-2 text-sm font-semibold transition',
                'bg-ink text-white' => $active,
                'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => ! $active,
            ]) @if($active) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <x-ui.kpis :groups="['payables']" />
</div>
