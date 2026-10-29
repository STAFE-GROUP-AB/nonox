@php($company_name = $company->legal_name ?: $company->name)
<!doctype html>
<html lang="sv">
<body style="font-family: -apple-system, 'Segoe UI', sans-serif; color: #1f2933; line-height: 1.5;">
    <p>{{ __('Hej!') }}</p>
    <p>{{ __('Vi har inte fått betalt för faktura :number, som förföll :due. Det är kanske bara förbisett.', ['number' => $invoice->number, 'due' => $invoice->due_date->toDateString()]) }}</p>
    <table style="border-collapse: collapse; margin: 1em 0;">
        <tr><td style="padding: 2px 16px 2px 0; color: #616e7c;">{{ __('Att betala') }}</td><td><strong>{{ $reminder->remaining_ore->format() }}</strong></td></tr>
        <tr><td style="padding: 2px 16px 2px 0; color: #616e7c;">{{ __('OCR-nummer') }}</td><td>{{ $invoice->ocr }}</td></tr>
        @if($company->bankgiro)<tr><td style="padding: 2px 16px 2px 0; color: #616e7c;">{{ __('Bankgiro') }}</td><td>{{ $company->bankgiro }}</td></tr>@endif
        <tr><td style="padding: 2px 16px 2px 0; color: #616e7c;">{{ __('Betala senast') }}</td><td>{{ $reminder->pay_by->toDateString() }}</td></tr>
    </table>
    <p>{{ __('Fakturan finns som PDF i det här mejlet. Har du redan betalat kan du bortse från påminnelsen. Har du frågor, svara på det här mejlet.') }}</p>
    <p>{{ __('Med vänliga hälsningar') }}<br>{{ $company_name }}</p>
</body>
</html>
