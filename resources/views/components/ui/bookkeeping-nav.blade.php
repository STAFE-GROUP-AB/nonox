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
        'accounting.closing' => $can('fiscal-years:manage') ? __('Bokslut') : null,
        'accounting.fixedAssets' => $can('fiscal-years:manage') ? __('Anläggningar') : null,
        'accounting.bankReconciliation' => $can('vouchers:post') ? __('Bankavstämning') : null,
        'accounting.accruals' => $can('fiscal-years:manage') ? __('Periodiseringar') : null,
        'accounting.voucherSeries' => __('Verifikationsserier'),
        'accounting.costCenters' => __('Kostnadsställen'),
        'accounting.projects' => __('Projekt'),
        'accounting.sieImports.index' => $can('import:sie') ? __('Läs in SIE-fil') : null,
    ]);
@endphp

<div class="space-y-6">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Bokföring') }}">
        @foreach($links as $route => $label)
            <a href="{{ route($route) }}" @class([
                'rounded-full px-4 py-2 text-sm font-semibold transition',
                'bg-ink text-white' => request()->routeIs(str_replace('.index', '.*', $route)),
                'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => ! request()->routeIs(str_replace('.index', '.*', $route)),
            ]) @if(request()->routeIs(str_replace('.index', '.*', $route))) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <x-ui.kpis :groups="['ledger']" />
</div>
