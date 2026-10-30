{{-- The dark sidebar: the company card with the company menu, the main navigation, and the user at the bottom. --}}
<div class="flex h-full flex-col gap-6 overflow-y-auto px-4 py-5">
    @if($currentCompany)
        <x-dropdown align="left" width="60" dropdownClasses="!w-[16rem]">
            <x-slot name="trigger">
                <button type="button" class="flex w-full items-center gap-3 rounded-2xl bg-white/[0.06] p-3 text-left ring-1 ring-white/10 transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-500">
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
                                <input type="search" x-model="search" x-on:click.stop x-on:keydown.stop placeholder="{{ __('Sök företag') }}"
                                       class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                            </div>
                        @endif

                        <div class="max-h-80 overflow-y-auto">
                            @foreach ($companies as $company)
                                <div x-show="search === '' || {{ Js::from(mb_strtolower($company->name.' '.$company->org_number)) }}.includes(search.toLowerCase())">
                                    <x-switchable-team :team="$company" />
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </x-slot>
        </x-dropdown>
    @else
        <a href="{{ route('dashboard') }}" class="px-2"><x-application-mark class="h-7 w-auto brightness-0 invert" /></a>
    @endif

    <nav class="flex-1 space-y-1" aria-label="{{ __('Huvudmeny') }}">
        @foreach($mainNav as $item)
            @php($active = request()->routeIs(...$item['active']))
            <a href="{{ route($item['route']) }}" @class([
                'block rounded-xl px-4 py-2.5 text-[15px] font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-500',
                'bg-accent-500/15 text-white shadow-[inset_3px_0_0_rgb(var(--accent-500))]' => $active,
                'text-stone-300 hover:bg-white/5 hover:text-white' => ! $active,
            ]) @if($active) aria-current="page" @endif>{{ $item['label'] }}</a>
        @endforeach
    </nav>

    <div class="space-y-4 border-t border-white/10 pt-4">
        <div class="flex items-center gap-3 px-2">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-sm font-semibold text-white" aria-hidden="true">{{ $initial(Auth::user()->name) }}</span>
            <span class="min-w-0">
                <span class="block truncate text-sm font-medium text-white">{{ Auth::user()->name }}</span>
                <span class="block truncate text-xs text-stone-400">{{ Auth::user()->email }}</span>
            </span>
        </div>
        <div class="flex flex-wrap gap-x-4 gap-y-1 px-2 text-sm">
            <a href="{{ route('profile.show') }}" class="text-stone-300 hover:text-white">{{ __('Profile') }}</a>
            @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
                <a href="{{ route('api-tokens.index') }}" class="text-stone-300 hover:text-white">{{ __('API Tokens') }}</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-stone-300 hover:text-white">{{ __('Log Out') }}</button>
            </form>
        </div>
        @if($currentYear)
            <p class="px-2 text-sm text-stone-500">{{ __('Räkenskapsår :year', ['year' => $currentYear->label()]) }}</p>
        @endif
    </div>
</div>
