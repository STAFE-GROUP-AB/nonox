@component('mail::message')
# {{ __('Förnya bankkopplingen') }}

{{ __('Medgivandet som låter NONOX läsa bankkonton åt :company går snart ut:', ['company' => $company]) }}

@foreach($banks as $bank)
- {{ __(':bank, till och med :date', $bank) }}
@endforeach

{{ __('Efter det hämtas inga fler transaktioner, och bankavstämningen får fyllas i för hand. Förnya genom att koppla banken igen med BankID. Kopplingen av konton till bokföringen följer med.') }}

@component('mail::button', ['url' => $url])
{{ __('Öppna Bank') }}
@endcomponent
@endcomponent
