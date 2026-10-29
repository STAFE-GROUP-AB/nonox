@php
    $links = [
        'invoicing.invoices.index' => __('Fakturor'),
        'invoicing.customers' => __('Kunder'),
        'invoicing.articles' => __('Artiklar'),
    ];
@endphp

<nav class="flex flex-wrap gap-2 print:hidden" aria-label="{{ __('Försäljning') }}">
    @foreach($links as $route => $label)
        @php($active = request()->routeIs(str_replace('.index', '.*', $route)))
        <a href="{{ route($route) }}" @class([
            'rounded-full px-4 py-1.5 text-sm font-medium',
            'bg-accent-600 text-white' => $active,
            'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => ! $active,
        ]) @if($active) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
