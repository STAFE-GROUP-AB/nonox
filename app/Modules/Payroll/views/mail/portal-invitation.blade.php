@component('mail::message')
# {{ __('Hej :name!', ['name' => $firstName]) }}

{{ __(':company använder Min sida för lönebesked. Där ser du dina lönebesked och kan spara dem som PDF.', ['company' => $company]) }}

## {{ __('Så loggar du in första gången') }}

1. {{ __('Klicka på knappen nedan.') }}
2. {{ __('Skriv din e-postadress, :email.', ['email' => $email]) }}
3. {{ __('Du får ett mejl med en engångskod. Skriv in koden. Den gäller i :minutes minuter.', ['minutes' => $minutes]) }}
4. {{ __('Nu ser du dina lönebesked.') }}

@component('mail::button', ['url' => $acceptUrl])
{{ __('Öppna Min sida') }}
@endcomponent

## {{ __('Nästa gång') }}

{{ __('Gå till :url och logga in på samma sätt. Det finns inget lösenord att komma ihåg; du får en ny kod varje gång.', ['url' => $portalUrl]) }}

{{ __('Lönebeskeden visas när lönen är attesterad. Väntade du dig inte det här mejlet kan du bortse från det.') }}
@endcomponent
