{{--
    "Kunden i siffror": the key figures from the ledger (AccountFigures), big
    with tabular numerals. A private person's figures are only for those who
    may see the invoices (limited), as on the account card since stage 1;
    a prospect has none yet. compact puts them in two columns for a side card.
--}}
@props(['figures' => null, 'limited' => false, 'isCustomer' => false, 'compact' => false, 'title' => null])

@php
    $size = $compact ? 'text-base' : 'text-lg sm:text-xl';
    $money = fn (int $ore) => App\Support\Money\Money::ofOre($ore);
    $rows = $figures === null ? [] : [
        ['label' => __('Fakturerat 12 mån'), 'money' => $figures->invoiced12Months],
        ['label' => __('Fakturerat totalt'), 'money' => $figures->invoicedTotal],
        ['label' => __('Obetalt'), 'money' => $figures->open],
        ['label' => __('Förfallet'), 'money' => $figures->overdue, 'warn' => $figures->overdue > 0],
        ['label' => __('Betaltid i snitt'), 'text' => $figures->paymentDays === null ? '–' : __(':days dagar', ['days' => number_format($figures->paymentDays, 1, ',', ' ')])],
        ['label' => __('Täckningsbidrag 12 mån'), 'money' => $figures->margin, 'rate' => $figures->marginRate()],
    ];
@endphp

<section {{ $attributes->merge(['class' => 'rounded-3xl bg-white p-6 shadow-xs ring-1 ring-stone-200 sm:p-8']) }} aria-labelledby="crm-figures-title">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="crm-figures-title" class="text-lg font-bold tracking-tight text-ink">{{ $title ?? __('Kunden i siffror') }}</h2>
        @isset($action){{ $action }}@endisset
    </div>
    <p class="mt-1 text-sm text-gray-600">
        {{ $limited ? __('En privatpersons köp och skulder visas bara för den som får se fakturorna.') : ($isCustomer ? __('Ur bokföringen, i kronor.') : __('Visas när prospektet har blivit kund och fått sin första faktura.')) }}
    </p>

    @if($isCustomer && ! $limited && $figures !== null)
        <dl @class(['mt-5 grid gap-px overflow-hidden rounded-2xl bg-stone-200 ring-1 ring-stone-200', 'grid-cols-2' => $compact, 'grid-cols-2 sm:grid-cols-3 2xl:grid-cols-6' => ! $compact]) data-testid="crm-figures">
            @foreach($rows as $row)
                <div class="bg-white px-4 py-4">
                    <dt class="text-xs font-medium text-gray-500">{{ $row['label'] }}</dt>
                    <dd class="mt-1.5">
                        @if(array_key_exists('text', $row))
                            <span class="{{ $size }} font-bold tracking-tight text-ink tabular-nums">{{ $row['text'] }}</span>
                        @elseif($row['money'] === null)
                            <span class="{{ $size }} font-bold tracking-tight text-ink">–</span>
                        @else
                            <x-ui.amount :money="$money($row['money'])" @class([$size.' font-bold tracking-tight', 'text-accent-700' => $row['warn'] ?? false, 'text-ink' => ! ($row['warn'] ?? false)]) />
                            @if(($row['rate'] ?? null) !== null)
                                <span class="ms-1 text-xs font-medium text-gray-500 tabular-nums">{{ number_format($row['rate'] * 100, 0, ',', ' ') }} %</span>
                            @endif
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
        @if($figures->marginCoverage !== null)
            <p class="mt-3 text-xs text-gray-500">{{ __('Täckningsbidraget bygger på :share % av försäljningen: raderna med en artikel som har inköpspris.', ['share' => number_format($figures->marginCoverage * 100, 0, ',', ' ')]) }}</p>
        @endif
    @endif

    {{ $slot }}
</section>
