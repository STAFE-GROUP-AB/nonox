<!doctype html>
<html lang="sv">
<body style="font-family: -apple-system, 'Segoe UI', sans-serif; color: #1f2933; line-height: 1.5;">
    <p>{{ __('Hej!') }}</p>
    <p>{{ __('Här kommer inköpsorder :number från :company.', ['number' => $order['number'], 'company' => $companyName]) }}@if(($order['version'] ?? 1) > 1) {{ __('Det är version :version och den ersätter tidigare versioner.', ['version' => $order['version']]) }}@endif</p>
    <p>{{ __('Du hittar ordern som PDF i det här mejlet. Bekräfta gärna ordern och leveransdatum.') }}</p>
    <p>{{ __('Med vänliga hälsningar') }}<br>{{ $companyName }}</p>
    <p style="font-size: 12px; color: #616e7c;">
        {{ __('Mejlet skickas av :company genom ekonomisystemet NONOX.', ['company' => $companyName]) }}
        @if($replyTo) {{ __('Svar går till :email.', ['email' => $replyTo]) }}@endif
    </p>
</body>
</html>
