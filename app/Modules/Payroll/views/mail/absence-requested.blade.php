@component('mail::message')
# {{ __('Ny ansökan från :name', ['name' => $employeeName]) }}

{{ __('Svara under Lön → Ansökningar i :company. Vad ansökan gäller ser du där.', ['company' => $company]) }}

@component('mail::button', ['url' => $url])
{{ __('Öppna ansökningarna') }}
@endcomponent
@endcomponent
