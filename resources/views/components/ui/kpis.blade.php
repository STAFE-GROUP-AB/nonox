{{--
    The owner's key figures for one section: sales, payables or the ledger
    (all of them when no group is given). Each figure links to where it comes
    from. BusinessKpis decides what this user may see.
--}}
@props(['groups' => null])

@php
    $user = auth()->user();
    $company = $user?->currentCompany();
    $kpis = $company === null ? [] : ($groups === null ? app(App\Domain\Insights\BusinessKpis::class)->for($user, $company) : app(App\Domain\Insights\BusinessKpis::class)->for($user, $company, $groups));
@endphp

@if($kpis !== [])
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 print:hidden" aria-label="{{ __('Nyckeltal') }}">
        @foreach($kpis as $kpi)
            {{-- The first figure is the dark card, as on the phone in the landing page. --}}
            @php($dark = $loop->first && ! $kpi['warn'])
            <a @if($kpi['link']) href="{{ $kpi['link'] }}" @endif @class([
                'block rounded-3xl px-5 py-4 transition',
                'bg-ink text-white shadow-sm hover:bg-black' => $dark,
                'bg-white shadow-sm ring-1 ring-stone-200 hover:ring-stone-300' => ! $dark && ! $kpi['warn'],
                'bg-accent-50 ring-1 ring-accent-200 hover:ring-accent-300' => $kpi['warn'],
            ])>
                <span @class(['block text-sm', 'text-stone-300' => $dark, 'text-gray-500' => ! $dark])>{{ $kpi['label'] }}</span>
                <x-ui.amount :money="$kpi['value']" :on-dark="$dark" @class(['mt-1 block text-2xl font-bold tracking-tight', 'text-white' => $dark, 'text-ink' => ! $dark]) />
                @if($kpi['hint'])
                    <span @class(['mt-2 inline-flex rounded-full text-xs', 'bg-white/10 px-2.5 py-1 text-stone-200' => $dark, 'text-accent-800' => $kpi['warn'], 'text-gray-500' => ! $dark && ! $kpi['warn']])>{{ $kpi['hint'] }}</span>
                @endif
            </a>
        @endforeach
    </section>
@endif
