<!doctype html>
<html lang="sv">
<body style="font-family: -apple-system, 'Segoe UI', sans-serif; color: #1f2933; line-height: 1.5;">
    <p>{{ __('Hej!') }}</p>
    <p>
        {{ __('Vi bekräftar leveransdatum för er order :number: :date.', ['number' => $number, 'date' => $date]) }}
        @if($previous) {{ __('Det ersätter det tidigare datumet :previous.', ['previous' => $previous]) }}@endif
    </p>
    <p>{{ __('Hör gärna av er om datumet inte passar.') }}</p>
    <p>{{ __('Med vänliga hälsningar') }}<br>{{ $companyName }}</p>
    <p style="font-size: 12px; color: #616e7c;">
        {{ __('Mejlet skickas av :company genom ekonomisystemet NONOX.', ['company' => $companyName]) }}
        @if($replyTo) {{ __('Svar går till :email.', ['email' => $replyTo]) }}@endif
    </p>
</body>
</html>
