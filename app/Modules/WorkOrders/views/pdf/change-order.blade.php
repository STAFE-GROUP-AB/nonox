@php($kr = fn (int $ore): string => App\Support\Money\Money::ofOre($ore)->format())
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ $changeOrder->label() }}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1f2933; }
    h1 { font-size: 16pt; margin: 0 0 2mm; }
    h2 { font-size: 11pt; margin: 6mm 0 2mm; }
    .muted { color: #616e7c; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 1mm 0; vertical-align: top; }
    .right { text-align: right; }
    .box { border: 1px solid #cbd2d9; padding: 3mm; margin-top: 4mm; }
</style>
</head>
<body>
    <p class="muted">{{ $company->legal_name ?: $company->name }} · {{ __('Org.nr :number', ['number' => $company->org_number]) }}</p>
    <h1>{{ $changeOrder->label() }}: {{ $changeOrder->title }}</h1>
    <p class="muted">{{ __('Ändrings- eller tilläggsarbete för arbetsorder :number, :title', ['number' => $workOrder->number, 'title' => $workOrder->title]) }}<br>
        {{ __('Kund: :name', ['name' => $customer->name]) }}@if($workOrder->standard_contract) · {{ __('Avtal: :contract', ['contract' => $workOrder->standard_contract]) }}@endif</p>

    <h2>{{ __('Vad som ska göras') }}</h2>
    <p>{!! nl2br(e($changeOrder->description)) !!}</p>

    <table>
        <tr><td class="muted">{{ __('Grund') }}</td><td>{{ $changeOrder->basis->label() }}</td></tr>
        <tr><td class="muted">{{ __('Prisform') }}</td><td>{{ $changeOrder->price_form->label() }}@if($changeOrder->limit_ore) · {{ __('högst :limit inklusive moms', ['limit' => $kr($changeOrder->limit_ore->ore)]) }}@endif</td></tr>
        <tr><td class="muted">{{ __('Påverkan på tiden') }}</td><td>{{ $changeOrder->time_effect ?: __('Ingen påverkan') }}</td></tr>
        <tr><td class="muted">{{ __('Betalning') }}</td><td>{{ $changeOrder->payment_terms ?: __('Med nästa faktura') }}</td></tr>
        @if($workOrder->tax_reduction)
            <tr><td class="muted">{{ App\Domain\Invoicing\TaxReduction::label($workOrder->tax_reduction) }}</td><td>{{ $changeOrder->tax_reduction_applies ? __('Ja, på arbetskostnaden') : __('Nej, inte på detta ÄTA') }}@if($workOrder->property_designation) · {{ $workOrder->property_designation }}@endif</td></tr>
        @endif
    </table>

    <h2>{{ __('Pris') }}</h2>
    <table>
        <tr><td>{{ __('Arbete') }}</td><td class="right">{{ $kr($changeOrder->labour_ore->ore) }}</td></tr>
        <tr><td>{{ __('Material') }}</td><td class="right">{{ $kr($changeOrder->material_ore->ore) }}</td></tr>
        <tr><td>{{ __('Övrigt, till exempel resor och maskiner') }}</td><td class="right">{{ $kr($changeOrder->other_ore->ore) }}</td></tr>
        <tr><td class="muted">{{ __('Summa exklusive moms') }}</td><td class="right">{{ $kr($amounts['net']) }}</td></tr>
        <tr><td class="muted">{{ __('Moms') }}</td><td class="right">{{ $kr($amounts['vat']) }}</td></tr>
        <tr><td><strong>{{ __('Summa inklusive moms') }}</strong></td><td class="right"><strong>{{ $kr($amounts['total']) }}</strong></td></tr>
        @if($amounts['deduction'] > 0)
            <tr><td class="muted">{{ __('Beräknat :type', ['type' => App\Domain\Invoicing\TaxReduction::label((string) $workOrder->tax_reduction)]) }}</td><td class="right">{{ $kr(-$amounts['deduction']) }}</td></tr>
            <tr><td><strong>{{ __('Att betala') }}</strong></td><td class="right"><strong>{{ $kr($amounts['to_pay']) }}</strong></td></tr>
        @endif
    </table>
    @if($workOrder->reverse_charge)
        <p><strong>{{ __('Omvänd betalningsskyldighet för byggtjänster.') }}</strong> {{ __('Köparen är skyldig att redovisa och betala momsen.') }}</p>
    @endif
    @if($amounts['deduction'] > 0)
        <p class="muted">{{ __('Avdraget förutsätter att Skatteverket godkänner det och att det ryms i köparens tak för året; annars betalar köparen resten.') }}</p>
    @endif

    @if($withDecision && $changeOrder->status->isDecided())
        <div class="box">
            <strong>{{ $changeOrder->status->label() }}</strong>
            @if($changeOrder->decided_at) · {{ $changeOrder->decided_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}@endif
            @if($changeOrder->approved_by_name)<br>{{ __('Av :name', ['name' => $changeOrder->approved_by_name]) }}@if($changeOrder->approved_by_title), {{ $changeOrder->approved_by_title }}@endif @endif
            @if($changeOrder->oral_channel)<br>{{ __('Muntligt godkännande, :channel, registrerat av företaget.', ['channel' => mb_strtolower($changeOrder->oral_channel->label())]) }}@endif
            @if($changeOrder->performed_reason)
                <br>{{ __('Skäl: :reason', ['reason' => $changeOrder->performed_reason->label()]) }}
                <br>{{ __('Motivering: :text', ['text' => $changeOrder->justification]) }}
                @foreach($attempts as $attempt)
                    <br>{{ __('Försök att nå kunden: :at, :channel', ['at' => $attempt->attempted_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i'), 'channel' => mb_strtolower($attempt->channel->label())]) }}
                @endforeach
            @endif
            @if($changeOrder->document_sha256)<br><span class="muted">{{ __('Dokumentets kontrollsumma (SHA-256): :hash', ['hash' => $changeOrder->document_sha256]) }}</span>@endif
        </div>
    @endif
</body>
</html>
