@component('mail::message')
# {{ __('Inkorgen i :company i dag', ['company' => $companyName]) }}

- {{ __('Mottaget senaste dygnet: :count', ['count' => $counts['received']]) }}
- {{ __('Att granska: :count', ['count' => $counts['review']]) }}
- {{ __('Utkast klara att godkänna: :count', ['count' => $counts['ready']]) }}
- {{ __('Bokfört senaste dygnet: :count', ['count' => $counts['booked']]) }}

@component('mail::button', ['url' => $url])
{{ __('Öppna inkorgen') }}
@endcomponent
@endcomponent
