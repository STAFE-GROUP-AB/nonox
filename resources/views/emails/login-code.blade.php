<x-mail::message>
# {{ __('Din inloggningskod') }}

{{ __('Skriv in koden i fönstret där du loggar in:') }}

<x-mail::panel>
<span style="font-size: 28px; letter-spacing: 6px; font-weight: bold;">{{ $code }}</span>
</x-mail::panel>

{{ __('Koden gäller i :minutes minuter och kan bara användas en gång.', ['minutes' => $minutes]) }}

{{ __('Har du inte försökt logga in? Då kan du bortse från mejlet. Ingen kommer in på ditt konto utan koden.') }}

{{ config('app.name') }}
</x-mail::message>
