<x-mail::message>
# {{ __('En webhook har lagts till') }}

{{ __(':creator har lagt till en webhook för :company i NONOX. Från och med nu skickas de här händelserna till :url:', ['creator' => $creatorName, 'company' => $companyName, 'url' => $url]) }}

@foreach($events as $event)
- {{ $event }}
@endforeach

{{ __('Händelserna innehåller id, nummer, datum och belopp, inga personuppgifter.') }}

{{ __('Var det väntat? Då behöver du inte göra något.') }}

{{ __('Var det inte väntat? Logga in och ta bort webhooken under Anslutningar. Den slutar skickas direkt.') }}

{{ config('app.name') }}
</x-mail::message>
