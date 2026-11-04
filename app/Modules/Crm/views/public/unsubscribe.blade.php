@php($companyName = $company ? ($company->legal_name ?: $company->name) : null)
<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Avregistrera dig från utskick') }}</title>
    <style>
        body { margin: 0; padding: 48px 16px; background: #f4f4f2; color: #1f2933; font: 16px/1.55 -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; }
        main { max-width: 520px; margin: 0 auto; padding: 32px 24px; background: #fff; border-radius: 16px; }
        h1 { margin: 0 0 12px; font-size: 1.375rem; color: #15171c; }
        button { min-height: 48px; padding: 0 24px; border: 0; border-radius: 10px; background: #15171c; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        button:focus { outline: 3px solid #15171c; outline-offset: 3px; }
    </style>
</head>
<body>
<main>
    @if($state === 'gone')
        <h1>{{ __('Länken gäller inte längre') }}</h1>
        <p>{{ __('Vill du inte få fler utskick, svara på mejlet och be avsändaren att ta bort dig.') }}</p>
    @elseif($state === 'done')
        <h1 role="status">{{ __('Du är avregistrerad') }}</h1>
        <p>{{ __(':company skickar inga fler utskick till dig. Det kan ta en liten stund innan ett utskick som redan är på väg har gått klart.', ['company' => $companyName]) }}</p>
    @else
        <h1>{{ __('Avregistrera dig från utskick') }}</h1>
        <p>{{ __('Vill du sluta få utskick från :company?', ['company' => $companyName]) }}</p>
        <form method="post" action="{{ route('crm.mailing.unsubscribe.confirm', ['token' => $token]) }}">
            <button type="submit">{{ __('Ja, avregistrera mig') }}</button>
        </form>
    @endif
</main>
</body>
</html>
