<!DOCTYPE html>
<html lang="sv" dir="ltr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex">
        <meta name="color-scheme" content="light dark">
        <title>{{ __('Skanna lager') }} – NONOX</title>
        @include('partials.favicon')
        <script type="application/json" id="scanner-config">{!! json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) !!}</script>
        @vite([App\Modules\Inventory\Http\ScannerController::ENTRY_JS])
    </head>
    <body>
        {{-- On a computer: the app is made for the phone; the QR code opens it there. --}}
        <aside class="s-desktop" aria-labelledby="s-desktop-title">
            <div class="s-desktop-qr">{!! $qr !!}</div>
            <div>
                <h2 id="s-desktop-title">{{ __('Öppna Skanna på mobilen') }}</h2>
                <p>{{ __('Skanna QR-koden med telefonens kamera, logga in och lägg till sidan på hemskärmen. Den fungerar även utan nät i lagret.') }}</p>
                <p class="s-desktop-url">{{ $url }}</p>
                <button type="button" id="s-desktop-continue" class="s-button">{{ __('Fortsätt här på datorn') }}</button>
            </div>
        </aside>

        <div id="scanner" class="s-app">
            <noscript>{{ __('Skanna behöver JavaScript.') }}</noscript>
        </div>

        <div id="s-flash" class="s-flash" aria-hidden="true"></div>

        <div id="s-camera" class="s-camera" hidden role="dialog" aria-modal="true" aria-label="{{ __('Skanna med kameran') }}">
            <video id="s-video" class="s-video" playsinline muted></video>
            <div class="s-camera-frame" aria-hidden="true"></div>
            <p id="s-camera-status" class="s-camera-status" role="status"></p>
            <button type="button" id="s-camera-close" class="s-button s-camera-close">{{ __('Stäng kameran') }}</button>
        </div>
    </body>
</html>
