@php($start = $entry->start->setTimezone('Europe/Stockholm'))
@php($end = $entry->end->setTimezone('Europe/Stockholm'))
<!doctype html>
<html lang="sv">
<body style="font-family: -apple-system, 'Segoe UI', sans-serif; color: #1f2933; line-height: 1.5;">
    <p>{{ __('Hej!') }}</p>
    <p>{{ __(':name på :company bjuder in dig till ett möte.', ['name' => $senderName, 'company' => $companyName]) }}</p>
    <p>
        <strong>{{ $entry->summary }}</strong><br>
        {{ $start->translatedFormat('l j F Y') }}, {{ $start->format('H:i') }}–{{ $end->format('H:i') }}@if($entry->location)<br>{{ $entry->location }}@endif
    </p>
    <p>{{ __('Mötet finns som kalenderfil i det här mejlet. Öppna den för att lägga in mötet i din kalender. Svara på mejlet om tiden inte passar.') }}</p>
    <p>{{ __('Med vänliga hälsningar') }}<br>{{ $senderName }}<br>{{ $companyName }}</p>
    <p style="font-size: 12px; color: #52606d;">{{ __('Mejlet skickades av :name (:email) via NONOX. Ett svar går till :email.', ['name' => $senderName, 'email' => $senderEmail]) }}</p>
</body>
</html>
