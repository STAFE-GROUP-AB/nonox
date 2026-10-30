@component('mail::message')
# {{ __(':name: :what', ['name' => $employeeName, 'what' => $what]) }}

{{ __(':dates. Svara under Lön → Ansökningar i :company; frånvaron kommer med i lönen först när den är godkänd.', ['dates' => $dates, 'company' => $company]) }}

@component('mail::button', ['url' => $url])
{{ __('Öppna ansökningarna') }}
@endcomponent
@endcomponent
