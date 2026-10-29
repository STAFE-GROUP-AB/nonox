@component('mail::message')
# {{ __('Du har bjudits in till :company', ['company' => $invitation->team->name]) }}

{{ __('Klicka på knappen och logga in med den här e-postadressen. Har du inget konto skapas det när du loggar in första gången.') }}

@component('mail::button', ['url' => $acceptUrl])
{{ __('Gå med i :company', ['company' => $invitation->team->name]) }}
@endcomponent

{{ __('Väntade du dig inte den här inbjudan kan du bortse från mejlet.') }}
@endcomponent
