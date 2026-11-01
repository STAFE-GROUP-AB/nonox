<x-mail::message>
# {{ __('En passkey har lagts till') }}

{{ __('Hej :name! En passkey med namnet ":passkey" har just lagts till på ditt konto i NONOX.', ['name' => $userName, 'passkey' => $passkeyName]) }}

{{ __('Var det du? Då behöver du inte göra något.') }}

{{ __('Var det inte du? Logga in, ta bort passkeyn under Profil och välj "Logga ut från andra enheter". Byt också lösenord till din e-post, eftersom inloggningskoden skickas dit.') }}

{{ config('app.name') }}
</x-mail::message>
