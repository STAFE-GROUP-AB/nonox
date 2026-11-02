<x-mail::message>
# {{ __('Välkommen in, :name!', ['name' => $applicantName]) }}

{{ __('Du är godkänd och kan logga in i NONOX nu. Skriv din e-postadress på inloggningssidan så mejlar vi en kod. Inga lösenord att komma ihåg.') }}

<x-mail::button :url="$loginUrl">
{{ __('Logga in') }}
</x-mail::button>

{{ __('Första gången du loggar in lägger du upp ditt företag. Har du ett annat bokföringsprogram i dag kan du läsa in bokföringen med en SIE-fil.') }}

{{ __('NONOX är i öppen beta. Skarp lansering av version 1.0 sker den :date, och det du lägger in under betan ligger kvar.', ['date' => $launchDate]) }}

{{ config('app.name') }}
</x-mail::message>
