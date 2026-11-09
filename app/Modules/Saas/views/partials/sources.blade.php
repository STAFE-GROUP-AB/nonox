{{-- The contract lines behind a customer's MRR, each linking to its document. --}}
@php
    $byContract = [];
    foreach ($lines as $line) {
        $byContract[$line->sourceType->value.':'.$line->sourceId] ??= ['line' => $line, 'ore' => 0];
        $byContract[$line->sourceType->value.':'.$line->sourceId]['ore'] += $line->sekOre;
    }
@endphp
<ul class="space-y-0.5">
    @foreach($byContract as $contract)
        @php($line = $contract['line'])
        <li>
            @if($line->sourceType === \App\Modules\Saas\Domain\SourceType::RecurringInvoice && Route::has('invoicing.agreements.show'))
                <a href="{{ route('invoicing.agreements.show', ['agreement' => $line->sourceId]) }}" class="text-accent-700 hover:underline">{{ $line->sourceType->label() }}</a>
            @elseif($line->sourceType === \App\Modules\Saas\Domain\SourceType::CrmAgreement && Route::has('crm.agreements.show'))
                <a href="{{ route('crm.agreements.show', ['agreement' => (int) $line->sourceId]) }}" class="text-accent-700 hover:underline">{{ $line->sourceType->label() }}</a>
            @else
                {{ $line->sourceType->label() }}
            @endif
            <span class="text-gray-600">· {{ $line->description }} · {{ \App\Support\Money\Money::ofOre($contract['ore'])->format() }}{{ $line->currency !== 'SEK' ? ' ('.$line->currency.')' : '' }}</span>
        </li>
    @endforeach
</ul>
