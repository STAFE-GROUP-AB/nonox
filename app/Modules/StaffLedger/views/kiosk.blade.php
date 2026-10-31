<!DOCTYPE html>
<html lang="sv" dir="ltr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#17171a">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="robots" content="noindex">
        <title>{{ __('Personalliggare') }} – NONOX</title>
        <link rel="manifest" href="{{ route('staff-ledger.kiosk.manifest', absolute: false) }}">
        <link rel="icon" href="{{ route('staff-ledger.kiosk.icon', absolute: false) }}" type="image/svg+xml">
        <link rel="apple-touch-icon" href="{{ route('staff-ledger.kiosk.icon', absolute: false) }}">
        <script type="application/json" id="kiosk-config">{!! json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) !!}</script>
        @vite([App\Modules\StaffLedger\Http\KioskController::ENTRY_JS])
    </head>
    <body>
        <div id="kiosk" aria-live="polite">
            <noscript>{{ __('Kiosken behöver JavaScript.') }}</noscript>
        </div>
    </body>
</html>
