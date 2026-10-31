<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('QR-kort för :name', ['name' => $person->name()]) }}</title>
    <style>
        body { margin: 0; font-family: ui-sans-serif, system-ui, sans-serif; color: #17171a; background: #f7f5f0; }
        .card { width: 85.6mm; min-height: 54mm; margin: 24px auto; padding: 6mm; box-sizing: border-box; background: #fff; border: 1px solid #d6d3cd; border-radius: 4mm; display: flex; gap: 5mm; align-items: center; }
        .card svg { width: 38mm; height: 38mm; flex: none; }
        h1 { font-size: 14pt; margin: 0 0 2mm; }
        p { margin: 0 0 1.5mm; font-size: 9pt; }
        .hint { max-width: 85.6mm; margin: 0 auto; font-size: 10pt; text-align: center; }
        button { display: block; margin: 16px auto; min-height: 48px; padding: 0 24px; border: 0; border-radius: 12px; background: #d4330b; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        @media print { body { background: #fff; } .hint, button { display: none; } .card { margin: 0; border: 1px solid #999; } }
    </style>
</head>
<body>
    <div class="card">
        {!! $qr !!}
        <div>
            <h1>{{ $person->name() }}</h1>
            <p>{{ $company->name }}</p>
            <p>{{ __('Personalliggare') }}</p>
            <p>{{ __('Visa koden för kioskens kamera för att checka in eller ut.') }}</p>
        </div>
    </div>
    <p class="hint">{{ __('Kortet är personligt. Skapa en ny kod under Personal om kortet kommer bort; den gamla slutar då att fungera.') }}</p>
    <button type="button" onclick="window.print()">{{ __('Skriv ut') }}</button>
</body>
</html>
