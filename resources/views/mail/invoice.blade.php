@php($company_name = $company->legal_name ?: $company->name)
<!doctype html>
<html lang="sv">
<body style="font-family: -apple-system, 'Segoe UI', sans-serif; color: #1f2933; line-height: 1.5;">
    <p>{{ __('Hej!') }}</p>
    @if($invoice->isCredit())
        <p>{{ __('Här kommer kreditfaktura :number från :company. Den finns som PDF i det här mejlet.', ['number' => $invoice->number, 'company' => $company_name]) }}</p>
    @else
        <p>{{ __('Här kommer faktura :number från :company på :amount. Den finns som PDF i det här mejlet.', ['number' => $invoice->number, 'company' => $company_name, 'amount' => $invoice->total_ore->format()]) }}</p>
        <p>
            {{ __('Betala senast :date', ['date' => $invoice->due_date->toDateString()]) }}@if($company->bankgiro) {{ __('till bankgiro :bankgiro', ['bankgiro' => $company->bankgiro]) }}@endif
            {{ __('och ange OCR-nummer :ocr.', ['ocr' => $invoice->ocr]) }}
        </p>
    @endif
    <p>{{ __('Med vänliga hälsningar') }}<br>{{ $company_name }}</p>
</body>
</html>
