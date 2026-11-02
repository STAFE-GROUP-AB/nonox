@php
    // One navigation tree for the sidebar and the drawer (App\Support\Navigation): sections, and the pages of the open one.
    $navUser = auth()->user();
    $navCompany = $navUser?->currentCompany();
    $currentCompany = $navCompany;
    // A client of an accounting firm shows the firm's logo to its own people (ADR-030); the firm's consultants see the company as before.
    $navBrand = $navCompany === null ? null : App\Support\Branding\FirmBranding::forViewer($navUser, $navCompany);
    $firmBrand = $navBrand?->viaFirm() ? $navBrand : null;
    $companies = $navUser->companies();
    $currentYear = $navCompany === null ? null : App\Models\FiscalYear::query()
        ->whereDate('start_date', '<=', now()->toDateString())
        ->whereDate('end_date', '>=', now()->toDateString())
        ->first();

    $sections = app(App\Support\Navigation\Navigation::class)->for($navUser, $navCompany, request()->route()?->getName());
    $openSection = collect($sections)->first(fn (App\Support\Navigation\NavSection $section): bool => $section->active);
    $openPage = $openSection?->current();

    $initial = fn (string $name): string => mb_strtoupper(mb_substr(trim($name), 0, 1));
    // The bell: unread notices about tasks (ADR-028), one query.
    $unreadNotices = $navUser->unreadNotifications()->count();
    // Hjälp: the manual's article about this page (ADR-036), from the cached index; null when the public pages are off.
    $helpUrl = App\Support\Manual\Manual::helpUrl(request()->route()?->getName());
@endphp

<div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    {{-- Phones and tablets: a dark bar with the company and the menu button. --}}
    <div data-sticky-bar class="sticky top-0 z-40 flex items-center justify-between gap-3 bg-ink px-4 py-3 text-white lg:hidden">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3">
            @if($firmBrand?->logoUrl())
                <span class="flex h-9 w-14 shrink-0 items-center justify-center rounded-lg bg-white p-1" aria-hidden="true"><img src="{{ $firmBrand->logoUrl() }}" alt="" class="max-h-7 max-w-full object-contain"></span>
            @else
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-500 font-bold text-white" aria-hidden="true">{{ $currentCompany ? $initial($currentCompany->name) : 'N' }}</span>
            @endif
            <span class="min-w-0">
                <span class="block truncate font-semibold leading-tight">{{ $currentCompany?->name ?? 'NONOX' }}</span>
                @if($openSection && $openSection->route !== 'dashboard')
                    {{-- Where you are, since the pages of the section are in the menu on a small screen. --}}
                    <span class="block truncate text-xs text-stone-300">{{ $openSection->label }}@if($openPage && $openPage->label !== $openSection->label) · {{ $openPage->label }}@endif</span>
                @endif
            </span>
        </a>
        <div class="ml-auto flex shrink-0 items-center gap-1">
            @if($currentCompany)
                {{-- The spotlight (ADR-026), full screen on a phone. --}}
                <button type="button" x-on:click="window.dispatchEvent(new CustomEvent('spotlight-open'))" class="rounded-lg p-2.5 text-stone-300 hover:bg-white/10 hover:text-white focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500" aria-label="{{ __('Sök') }}" aria-haspopup="dialog">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                </button>
            @endif
            @include('navigation.help', ['helpClass' => 'rounded-lg p-2.5 text-stone-300 hover:bg-white/10 hover:text-white', 'helpLabel' => false])
            @include('navigation.bell', ['bellClass' => 'text-stone-300 hover:bg-white/10 hover:text-white'])
            <button type="button" x-on:click="open = true" class="rounded-lg p-2.5 text-stone-300 hover:bg-white/10 hover:text-white focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500" aria-label="{{ __('Öppna menyn') }}">
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
            </button>
        </div>
    </div>

    {{-- The menu slides in from the left on small screens. --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="{{ __('Meny') }}">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/50" x-on:click="open = false"></div>
        {{-- Where the menu button was, beside the panel, so it never covers the company switcher. --}}
        <button type="button" x-on:click="open = false" class="absolute right-4 top-3 rounded-lg bg-ink p-2.5 text-white hover:text-stone-300" aria-label="{{ __('Stäng menyn') }}">
            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
        <aside x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
               x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
               class="absolute inset-y-0 left-0 flex w-80 max-w-[85vw] flex-col bg-ink">
            @include('navigation.sidebar')
        </aside>
    </div>

    {{-- Desktop: the sidebar stays in place. --}}
    <aside class="hidden lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:flex lg:w-72 lg:flex-col bg-ink" aria-label="{{ __('Huvudmeny') }}">
        @include('navigation.sidebar')
    </aside>
</div>
