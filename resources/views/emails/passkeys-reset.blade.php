<x-mail::message>
# {{ __('Dina passkeys har återställts') }}

@if($companyName)
{{ __('Hej :name! :by har tagit bort alla passkeys på ditt konto, via företaget :company. Du har också loggats ut från alla enheter.', ['name' => $userName, 'by' => $resetBy, 'company' => $companyName]) }}
@else
{{ __('Hej :name! :by har tagit bort alla passkeys på ditt konto. Du har också loggats ut från alla enheter.', ['name' => $userName, 'by' => $resetBy]) }}
@endif

{{ __('Nästa gång du loggar in lägger du till en ny passkey, till exempel med fingeravtryck eller ansiktsigenkänning på din telefon eller dator.') }}

{{ __('Bad du inte om det här? Kontakta :by eller någon annan som administrerar företaget, och byt lösenord till din e-post.', ['by' => $resetBy]) }}

{{ config('app.name') }}
</x-mail::message>
