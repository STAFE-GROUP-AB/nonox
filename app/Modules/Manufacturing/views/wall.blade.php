<!DOCTYPE html>
<html lang="sv" dir="ltr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#111113">
        <meta name="robots" content="noindex">
        <title>{{ __('Storskärm i verkstaden') }} – NONOX</title>
        <link rel="icon" href="{{ route('manufacturing.terminal.icon', absolute: false) }}" type="image/svg+xml">
        <script type="application/json" id="wall-config">{!! json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) !!}</script>
        @vite([App\Modules\Manufacturing\Http\WallScreenController::ENTRY_JS])
    </head>
    <body>
        <div id="wall">
            <noscript>{{ __('Storskärmen behöver JavaScript.') }}</noscript>
        </div>
    </body>
</html>
