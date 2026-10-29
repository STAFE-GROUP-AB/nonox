@php($company_name = $company->legal_name ?: $company->name)
<!doctype html>
<html lang="sv">
<body style="font-family: -apple-system, 'Segoe UI', sans-serif; color: #1f2933; line-height: 1.5;">
    <p>{{ __('Hej!') }}</p>
    @if($document->isQuote())
        <p>{{ __('Här kommer offert :number från :company på :amount.', ['number' => $document->number, 'company' => $company_name, 'amount' => $document->total_ore->format()]) }}@if($document->until_date) {{ __('Den gäller till och med :date.', ['date' => $document->until_date->toDateString()]) }}@endif</p>
    @else
        <p>{{ __('Tack för din beställning. Här kommer orderbekräftelse :number från :company.', ['number' => $document->number, 'company' => $company_name]) }}</p>
    @endif
    <p>{{ __('Du hittar den som PDF i det här mejlet.') }}</p>
    <p>{{ __('Med vänliga hälsningar') }}<br>{{ $company_name }}</p>
</body>
</html>
