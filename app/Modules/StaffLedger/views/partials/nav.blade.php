@php
    $slUser = auth()->user();
    $slCompany = $slUser->currentCompany();
    $slOn = app(App\Modules\ModuleManager::class)->isEnabled(App\Modules\StaffLedger\StaffLedgerModule::KEY, $slCompany);
    $slItems = array_filter([
        'staff-ledger.overview' => $slOn && $slUser->hasTeamPermission($slCompany, 'staff-ledger:view') ? __('Översikt') : null,
        'staff-ledger.ledger' => $slUser->hasTeamPermission($slCompany, 'staff-ledger:view') ? __('Liggaren') : null,
        'staff-ledger.people' => $slOn && $slUser->hasTeamPermission($slCompany, 'staff-ledger:view') ? __('Personal') : null,
        'staff-ledger.locations' => $slOn && $slUser->hasTeamPermission($slCompany, 'staff-ledger:view') ? __('Lokaler och kiosker') : null,
        'staff-ledger.proposals' => $slOn && $slUser->hasTeamPermission($slCompany, 'staff-ledger:manage') && app(App\Modules\ModuleManager::class)->isEnabled(App\Modules\Payroll\PayrollModule::KEY, $slCompany) ? __('Tidrapporter') : null,
    ]);
@endphp
<nav class="flex flex-wrap items-center gap-2 print:hidden" aria-label="{{ __('Personalliggare') }}">
    @foreach($slItems as $route => $label)
        @php($active = request()->routeIs($route))
        <a href="{{ route($route) }}" wire:navigate @class([
            'rounded-full px-4 py-2 text-sm font-semibold transition',
            'bg-ink text-white' => $active,
            'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => ! $active,
        ]) @if($active) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
    @if($slUser->hasTeamPermission($slCompany, 'staff-ledger:control-visit'))
        <a href="{{ route('staff-ledger.control-visit') }}" class="ms-auto inline-flex min-h-11 items-center rounded-full bg-accent-600 px-5 py-2 text-sm font-bold text-white hover:bg-accent-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2">{{ __('Skatteverket är här') }}</a>
    @endif
</nav>
