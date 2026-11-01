@php
    $tabs = [['route' => 'agreements.index', 'label' => __('Företag och anställda')], ['route' => 'agreements.versions', 'label' => __('Versioner och kalender')]];

    if (App\Modules\Agreements\Actions\AgreementAi::availableFor(auth()->user(), auth()->user()->currentCompany())) {
        $tabs[] = ['route' => 'agreements.ai', 'label' => __('NONOX AI')];
    }
@endphp
<nav class="flex flex-wrap gap-2" aria-label="{{ __('Avtal') }}">
    @foreach($tabs as $tab)
        <a href="{{ route($tab['route']) }}" @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-accent-50 text-accent-700' => request()->routeIs($tab['route']), 'text-gray-600 hover:text-gray-900' => ! request()->routeIs($tab['route'])]) @if(request()->routeIs($tab['route'])) aria-current="page" @endif>{{ $tab['label'] }}</a>
    @endforeach
</nav>
