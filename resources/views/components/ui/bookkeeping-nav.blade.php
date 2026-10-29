@php
    $user = auth()->user();
    $company = $user?->currentCompany();
    $can = fn (string $permission): bool => $company !== null && $user->hasTeamPermission($company, $permission);

    $links = array_filter([
        'accounting.vouchers.index' => __('Verifikationer'),
        'accounting.reports' => $can('reports:view') ? __('Rapporter') : null,
        'vat.index' => $can('vat:prepare') ? __('Moms') : null,
        'accounting.accounts' => __('Kontoplan'),
        'accounting.fiscalYears' => __('Räkenskapsår'),
        'accounting.voucherSeries' => __('Verifikationsserier'),
        'accounting.costCenters' => __('Kostnadsställen'),
        'accounting.projects' => __('Projekt'),
        'accounting.sieImports.index' => $can('import:sie') ? __('Läs in SIE-fil') : null,
    ]);
@endphp

<nav class="flex flex-wrap gap-2" aria-label="{{ __('Bokföring') }}">
    @foreach($links as $route => $label)
        <a href="{{ route($route) }}" @class([
            'rounded-full px-4 py-1.5 text-sm font-medium',
            'bg-accent-600 text-white' => request()->routeIs(str_replace('.index', '.*', $route)),
            'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => ! request()->routeIs(str_replace('.index', '.*', $route)),
        ]) @if(request()->routeIs(str_replace('.index', '.*', $route))) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
