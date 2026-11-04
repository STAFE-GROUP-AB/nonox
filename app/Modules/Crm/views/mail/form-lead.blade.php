@component('mail::message')
# {{ __('Ny intresseanmälan i :company', ['company' => $companyName]) }}

{{ __('Någon har skickat in formuläret :form. Det finns en uppgift att följa upp på din lista i CRM.', ['form' => $formName]) }}

@component('mail::button', ['url' => $url])
{{ __('Öppna i NONOX') }}
@endcomponent
@endcomponent
