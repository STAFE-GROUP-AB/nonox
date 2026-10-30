<div class="space-y-6">
    <nav class="flex flex-wrap gap-2 print:hidden" aria-label="{{ __('Inköp') }}">
        @foreach(['payables.invoices.index' => __('Leverantörsfakturor'), 'payables.batches.index' => __('Betalfiler')] as $route => $label)
            @php($active = request()->routeIs(str_replace('.index', '.*', $route)))
            <a href="{{ route($route) }}" @class([
                'rounded-full px-4 py-1.5 text-sm font-medium',
                'bg-accent-600 text-white' => $active,
                'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => ! $active,
            ]) @if($active) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <x-ui.kpis />
</div>
