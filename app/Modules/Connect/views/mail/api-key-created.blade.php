<x-mail::message>
# {{ __('En API-nyckel har skapats') }}

{{ __(':creator har skapat API-nyckeln ":name" för :company i NONOX.', ['creator' => $creatorName, 'name' => $keyName, 'company' => $companyName]) }}

{{ __('Nyckeln börjar med :prefix och gäller till och med :date. Den får:', ['prefix' => $prefix, 'date' => $expiresOn]) }}

@foreach($scopes as $scope)
- {{ $scope }}
@endforeach

{{ __('Var det väntat? Då behöver du inte göra något.') }}

{{ __('Var det inte väntat? Logga in och återkalla nyckeln under Anslutningar. Den slutar fungera direkt.') }}

{{ config('app.name') }}
</x-mail::message>
