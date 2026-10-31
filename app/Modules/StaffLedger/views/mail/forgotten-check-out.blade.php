@component('mail::message')
@if($toPerson)
# {{ __('Har du glömt att checka ut?') }}

{{ __('Du är fortfarande incheckad i personalliggaren hos :company:', ['company' => $company]) }}
@else
# {{ __('Glömda utcheckningar') }}

{{ __('De här personerna är fortfarande incheckade i personalliggaren hos :company:', ['company' => $company]) }}
@endif

@foreach($people as $person)
- {{ __(':name, :location, sedan :since', $person) }}
@endforeach

{{ __('Ingen checkas ut automatiskt. Checka ut vid kiosken, eller be en chef registrera rätt tid med en rättelse.') }}

@unless($toPerson)
@component('mail::button', ['url' => $url])
{{ __('Öppna personalliggaren') }}
@endcomponent
@endunless
@endcomponent
