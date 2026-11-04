{{-- The dark sidebar: the company card with the company menu, the main navigation, and the user at the bottom. --}}
<div class="flex h-full flex-col gap-6 overflow-y-auto px-4 py-5">
    @if($firmBrand)
        {{-- The accounting firm the company works with, in its own logo (ADR-030). --}}
        <div class="-mb-3 rounded-2xl bg-white px-4 py-3" data-firm-brand>
            @if($firmBrand->logoUrl())
                <img src="{{ $firmBrand->logoUrl() }}" alt="{{ $firmBrand->firmName }}" class="h-9 max-w-full object-contain object-left">
            @endif
            <p class="mt-1.5 truncate text-xs text-gray-600 first:mt-0">{{ __('i samarbete med') }} <span class="font-semibold text-gray-900">{{ $firmBrand->firmName }}</span></p>
        </div>
    @endif
    @if($currentCompany)
        <x-dropdown align="left" width="60" dropdownClasses="w-[16rem]!">
            <x-slot name="trigger">
                <button type="button" class="flex w-full items-center gap-3 rounded-2xl bg-white/6 p-3 text-left ring-1 ring-white/10 transition hover:bg-white/10 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent-500 text-lg font-bold text-white" aria-hidden="true">{{ $initial($currentCompany->name) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold leading-tight text-white">{{ $currentCompany->name }}</span>
                        <span class="block text-sm tabular-nums text-stone-400">{{ $currentCompany->org_number }}</span>
                    </span>
                    <svg class="size-4 shrink-0 text-stone-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                </button>
            </x-slot>

            <x-slot name="content">
                <div x-data="{ search: '' }">
                    <div class="block px-4 py-2 text-xs text-gray-400">{{ __('Företag') }}</div>
                    <x-dropdown-link href="{{ route('teams.show', $currentCompany->id) }}">{{ __('Inställningar för företaget') }}</x-dropdown-link>
                    <x-dropdown-link href="{{ route('teams.create') }}">{{ __('Lägg till ett företag') }}</x-dropdown-link>

                    @if ($companies->count() > 1)
                        <div class="border-t border-gray-200"></div>
                        <x-dropdown-link href="{{ route('companies.overview') }}">{{ __('Mina företag – vad som väntar') }}</x-dropdown-link>
                        <div class="block px-4 py-2 text-xs text-gray-400">{{ __('Byt företag') }}</div>

                        @if ($companies->count() > 5)
                            <div class="px-4 pb-2">
                                <input type="search" x-model="search" x-on:click.stop x-on:keydown.stop placeholder="{{ __('Sök företag') }}" aria-label="{{ __('Sök företag') }}"
                                       class="w-full rounded-lg border-gray-300 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500" />
                            </div>
                        @endif

                        <div class="max-h-80 overflow-y-auto">
                            {{-- Archived companies last, under their own heading (ADR-024). --}}
                            @foreach ($companies->partition(fn ($company): bool => ! $company->isArchived()) as $group => $groupCompanies)
                                @if($group === 1 && $groupCompanies->isNotEmpty())
                                    <div class="block px-4 pb-1 pt-3 text-xs text-gray-400">{{ __('Arkiverade företag') }}</div>
                                @endif
                                @foreach ($groupCompanies as $company)
                                    <div x-show="search === '' || {{ Js::from(mb_strtolower($company->name.' '.$company->org_number)) }}.includes(search.toLowerCase())">
                                        <x-switchable-team :team="$company" />
                                    </div>
                                @endforeach
                            @endforeach
                        </div>
                    @endif
                </div>
            </x-slot>
        </x-dropdown>
    @else
        <a href="{{ route('dashboard') }}" class="px-2"><x-application-mark class="h-7 w-auto brightness-0 invert" /></a>
    @endif

    {{-- The sections, and under the open one its pages (App\Support\Navigation). --}}
    <nav class="flex-1" aria-label="{{ __('Huvudmeny') }}">
        <ul class="space-y-0.5">
            @foreach($sections as $section)
                @php($ownPage = $section->active && ($section->current() === null))
                <li>
                    <a href="{{ $section->url() }}" @class([
                        'block rounded-xl px-4 py-2 text-[15px] font-medium transition focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500',
                        'bg-accent-500/15 text-white shadow-[inset_3px_0_0_rgb(var(--accent-500))]' => $section->active,
                        'text-stone-300 hover:bg-white/5 hover:text-white' => ! $section->active,
                    ]) @if($ownPage) aria-current="page" @endif>{{ $section->label }}</a>

                    @if($section->active && $section->hasChildren())
                        <div class="mb-3 ml-5 mt-1 border-l border-white/15 pl-2">
                            @foreach($section->groups() as $group)
                                @if($group['label'])
                                    <p class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wider text-stone-400 first:pt-1.5" aria-hidden="true">{{ $group['label'] }}</p>
                                @endif
                                <ul class="space-y-px" aria-label="{{ $group['label'] ?? $section->label }}">
                                    @foreach($group['links'] as $link)
                                        <li>
                                            <a href="{{ $link->url() }}" @if($link->active) aria-current="page" x-data x-init="$el.scrollIntoView({ block: 'nearest' })" @endif @class([
                                                'relative block rounded-lg px-3 py-1.5 text-[13px] leading-5 transition focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500',
                                                'bg-white/10 font-semibold text-white before:absolute before:left-[-9px] before:inset-y-1.5 before:w-0.5 before:rounded-full before:bg-accent-500' => $link->active,
                                                'text-stone-300 hover:bg-white/5 hover:text-white' => ! $link->active,
                                            ])>{{ $link->label }}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="space-y-4 border-t border-white/10 pt-4">
        <div class="flex items-center gap-3 px-2">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-sm font-semibold text-white" aria-hidden="true">{{ $initial(Auth::user()->name) }}</span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-white">{{ Auth::user()->name }}</span>
                <span class="block truncate text-xs text-stone-400">{{ Auth::user()->email }}</span>
            </span>
            {{-- The encrypted chat (ADR-045). --}}
            <x-secure-chat.trigger class="rounded-lg p-2 text-stone-300 hover:bg-white/10 hover:text-white" />
            @include('navigation.bell', ['bellClass' => 'text-stone-300 hover:bg-white/10 hover:text-white'])
        </div>
        {{-- At least 24 px high, so each link is easy to hit (WCAG 2.5.8). --}}
        <div class="flex flex-wrap gap-x-4 gap-y-1 px-2 text-sm [&_a:not(.inline-flex)]:inline-block [&_a]:py-1 [&_button]:py-1">
            @include('navigation.help', ['helpClass' => 'rounded text-stone-300 hover:text-white', 'helpLabel' => true])
            <a href="{{ route('profile.show') }}" class="text-stone-300 hover:text-white">{{ __('Profile') }}</a>
            @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
                <a href="{{ route('api-tokens.index') }}" class="text-stone-300 hover:text-white">{{ __('API Tokens') }}</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-stone-300 hover:text-white">{{ __('Log Out') }}</button>
            </form>
        </div>
        {{-- Företagsguiden's start plan (ADR-051), while the company shows the guide. --}}
        @if($currentCompany?->guide_enabled)
            <a href="{{ route('guide.plan') }}" class="mx-2 flex items-center gap-1.5 rounded py-1 text-sm text-stone-300 hover:text-white focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500" data-guide-link>
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6h11M9 12h11M9 18h11M4 6l1 1 2-2M4 12l1 1 2-2M4 18l1 1 2-2" /></svg>
                <span>{{ __('Företagsguiden') }}</span>
            </a>
        @endif
        @if($currentYear)
            <p class="px-2 text-sm text-stone-400">{{ __('Räkenskapsår :year', ['year' => $currentYear->label()]) }}</p>
        @endif
    </div>
</div>
