@php
    // Navigation of modules that are on for the company, filtered by permission (PAYROLL_MODULE.md A.5).
    $navUser = auth()->user();
    $navCompany = $navUser?->currentCompany();
    $moduleNav = [];

    if ($navCompany !== null) {
        $navEnabled = app(App\Modules\ModuleManager::class)->enabledModules($navCompany);

        foreach (app(App\Modules\ModuleRegistry::class)->all() as $navModule) {
            // A module that is off may still show its archive (PAYROLL_MODULE.md A.6).
            $navItems = in_array($navModule, $navEnabled, true) ? $navModule->navigation() : $navModule->archiveNavigation($navCompany);

            foreach ($navItems as $item) {
                if ($navUser->hasTeamPermission($navCompany, $item['permission'])) {
                    $moduleNav[] = $item;
                }
            }
        }
    }

    $currentCompany = $navCompany;
    $companies = $navUser->companies();
    $currentYear = $navCompany === null ? null : App\Models\FiscalYear::query()
        ->whereDate('start_date', '<=', now()->toDateString())
        ->whereDate('end_date', '>=', now()->toDateString())
        ->first();

    $mainNav = [
        ['route' => 'dashboard', 'label' => __('Dashboard'), 'active' => ['dashboard']],
        ['route' => 'invoicing.invoices.index', 'label' => __('Försäljning'), 'active' => ['invoicing.invoices.*', 'invoicing.quotes*', 'invoicing.orders*', 'invoicing.incomingPayments', 'invoicing.agreements*']],
        ['route' => 'payables.invoices.index', 'label' => __('Inköp'), 'active' => ['payables.invoices.*', 'payables.batches.*']],
        ['route' => 'invoicing.customers', 'label' => __('Register'), 'active' => ['invoicing.customers', 'invoicing.articles', 'payables.suppliers']],
        ['route' => 'accounting.vouchers.index', 'label' => __('Bokföring'), 'active' => ['accounting.*', 'vat.*']],
    ];

    foreach ($moduleNav as $item) {
        $mainNav[] = ['route' => $item['route'], 'label' => $item['label'], 'active' => (array) $item['active']];
    }

    if ($companies->count() > 1) {
        $mainNav[] = ['route' => 'companies.overview', 'label' => __('Mina företag'), 'active' => ['companies.overview']];
    }

    $initial = fn (string $name): string => mb_strtoupper(mb_substr(trim($name), 0, 1));
@endphp

<div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    {{-- Phones and tablets: a dark bar with the company and the menu button. --}}
    <div class="sticky top-0 z-40 flex items-center justify-between gap-3 bg-ink px-4 py-3 text-white lg:hidden">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-500 font-bold text-white" aria-hidden="true">{{ $currentCompany ? $initial($currentCompany->name) : 'N' }}</span>
            <span class="truncate font-semibold">{{ $currentCompany?->name ?? 'NONOX' }}</span>
        </a>
        <button type="button" x-on:click="open = true" class="rounded-lg p-2 text-stone-300 hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-500" aria-label="{{ __('Öppna menyn') }}">
            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
        </button>
    </div>

    {{-- The menu slides in from the left on small screens. --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="{{ __('Meny') }}">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/50" x-on:click="open = false"></div>
        <aside x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
               x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
               class="absolute inset-y-0 left-0 flex w-80 max-w-[85vw] flex-col bg-ink">
            <button type="button" x-on:click="open = false" class="absolute right-3 top-3 rounded-lg p-2 text-stone-400 hover:bg-white/10 hover:text-white" aria-label="{{ __('Stäng menyn') }}">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
            @include('navigation.sidebar')
        </aside>
    </div>

    {{-- Desktop: the sidebar stays in place. --}}
    <aside class="hidden lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:flex lg:w-72 lg:flex-col bg-ink" aria-label="{{ __('Huvudmeny') }}">
        @include('navigation.sidebar')
    </aside>
</div>
