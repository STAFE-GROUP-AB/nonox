{{--
    The owner's key figures, shown under every section's tabs. Each figure
    links to where it comes from. BusinessKpis decides what this user may see.
--}}
@php
    $user = auth()->user();
    $company = $user?->currentCompany();
    $kpis = $company === null ? [] : app(App\Domain\Insights\BusinessKpis::class)->for($user, $company);
@endphp

@if($kpis !== [])
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 print:hidden" aria-label="{{ __('Nyckeltal') }}">
        @foreach($kpis as $kpi)
            <a @if($kpi['link']) href="{{ $kpi['link'] }}" @endif @class([
                'block rounded-xl border bg-white px-4 py-3',
                'border-gray-200 hover:border-gray-300' => ! $kpi['warn'],
                'border-accent-300' => $kpi['warn'],
            ])>
                <span class="block text-sm text-gray-500">{{ $kpi['label'] }}</span>
                <x-ui.amount :money="$kpi['value']" class="mt-1 block text-xl font-semibold text-gray-900" />
                @if($kpi['hint'])
                    <span @class(['mt-0.5 block text-xs', 'text-accent-700' => $kpi['warn'], 'text-gray-500' => ! $kpi['warn']])>{{ $kpi['hint'] }}</span>
                @endif
            </a>
        @endforeach
    </section>
@endif
