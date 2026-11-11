@php
    $tabs = [
        ['route' => 'manufacturing.planning.timeline', 'label' => __('Tidslinje')],
        ['route' => 'manufacturing.planning.orders', 'label' => __('Ordrar')],
        ['route' => 'manufacturing.planning.resources', 'label' => __('Resurser')],
        ['route' => 'manufacturing.planning.calendar', 'label' => __('Kalender')],
        ['route' => 'manufacturing.planning.deviations', 'label' => __('Avvikelser')],
        ['route' => 'manufacturing.planning.scenario', 'label' => __('Tänk om')],
        ['route' => 'manufacturing.planning.setup', 'label' => __('Inställningar')],
    ];
@endphp
<nav class="flex flex-wrap gap-2" aria-label="{{ __('Planering') }}">
    @foreach($tabs as $tab)
        <a href="{{ route($tab['route']) }}" @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-accent-50 text-accent-700' => request()->routeIs($tab['route']), 'text-gray-600 hover:text-gray-900' => ! request()->routeIs($tab['route'])]) @if(request()->routeIs($tab['route'])) aria-current="page" @endif>{{ $tab['label'] }}</a>
    @endforeach
</nav>
