{{-- The public signing pages' head (ADR-046): never indexed, no Referer, NONOX's own CSS and the signing script only. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $pageTitle }}</title>
<link rel="icon" type="image/svg+xml" href="{{ asset('brand/nonox-icon.svg') }}">
@vite(['resources/css/app.css', 'resources/js/signing.js'])
