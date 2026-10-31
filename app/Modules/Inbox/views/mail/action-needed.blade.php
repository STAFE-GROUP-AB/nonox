@component('mail::message')
# {{ __('Inkorgen i :company behöver granskas', ['company' => $companyName]) }}

{{ $waiting === 1 ? __('Ett underlag väntar på att någon tittar på det.') : __(':count underlag väntar på att någon tittar på dem.', ['count' => $waiting]) }}

@component('mail::button', ['url' => $url])
{{ __('Öppna inkorgen') }}
@endcomponent
@endcomponent
