{{-- Payroll's key figures under the payroll tabs, in the same cards as the rest of the app. --}}
@php
    $payrollUser = auth()->user();
    $payrollCompany = $payrollUser?->currentCompany();
    $payrollKpis = $payrollCompany === null ? [] : app(App\Modules\Payroll\Support\Insights\PayrollKpis::class)->for($payrollUser, $payrollCompany);
@endphp

@if($payrollKpis !== [])
    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 print:hidden" aria-label="{{ __('Nyckeltal för lön') }}">
        @foreach($payrollKpis as $kpi)
            @php($dark = $loop->first)
            <a @if($kpi['link']) href="{{ $kpi['link'] }}" @endif @class([
                'block rounded-3xl px-5 py-4 transition',
                'bg-ink text-white shadow-xs hover:bg-black' => $dark,
                'bg-white shadow-xs ring-1 ring-stone-200 hover:ring-stone-300' => ! $dark && ! $kpi['warn'],
                'bg-accent-50 ring-1 ring-accent-200 hover:ring-accent-300' => ! $dark && $kpi['warn'],
            ])>
                <span @class(['block text-sm', 'text-stone-300' => $dark, 'text-gray-500' => ! $dark])>{{ $kpi['label'] }}</span>
                @if($kpi['value'] instanceof App\Support\Money\Money)
                    <x-ui.amount :money="$kpi['value']" :on-dark="$dark" @class(['mt-1 block text-2xl font-bold tracking-tight', 'text-white' => $dark, 'text-ink' => ! $dark]) />
                @else
                    <span @class(['mt-1 block text-2xl font-bold tracking-tight tabular-nums', 'text-white' => $dark, 'text-ink' => ! $dark])>{{ $kpi['value'] }}</span>
                @endif
                @if($kpi['hint'])
                    <span @class(['mt-2 inline-flex rounded-full text-xs', 'bg-white/10 px-2.5 py-1 text-stone-200' => $dark, 'text-accent-800' => ! $dark && $kpi['warn'], 'text-gray-500' => ! $dark && ! $kpi['warn']])>{{ $kpi['hint'] }}</span>
                @endif
            </a>
        @endforeach
    </section>
@endif
