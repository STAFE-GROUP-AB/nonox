<x-mail::message>
# {{ __('Tack, :name! Du står i kö.', ['name' => $applicantName]) }}

{{ __('Vi har tagit emot din anmälan till NONOX. Just nu är NONOX i öppen beta, och vi släpper in nya användare i den takt vi hinner hjälpa dem.') }}

**{{ __('Det här händer nu') }}**

1. {{ __('Vi läser dina svar och hör av oss om vi undrar något.') }}
2. {{ __('När du är godkänd får du ett mejl. Sedan loggar du in med din e-postadress och en kod som vi mejlar, inga lösenord.') }}
3. {{ __('Första gången du loggar in lägger du upp ditt företag. Det tar några minuter.') }}

{{ __('Skarp lansering av version 1.0 sker den :date. Det du lägger in under betan ligger kvar.', ['date' => $launchDate]) }}

{{ __('Har du inte anmält dig? Då kan du bortse från mejlet, eller svara på det så tar vi bort uppgifterna.') }} [{{ __('Så behandlar vi dina personuppgifter') }}]({{ $privacyUrl }})

{{ config('app.name') }}
</x-mail::message>
