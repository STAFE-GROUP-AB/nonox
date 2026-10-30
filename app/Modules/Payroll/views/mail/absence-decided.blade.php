@component('mail::message')
# {{ __('Hej :name!', ['name' => $firstName]) }}

{{ $approved
    ? __('Din ansökan, :what :dates, är godkänd av :company.', ['what' => mb_strtolower($what), 'dates' => $dates, 'company' => $company])
    : __('Din ansökan, :what :dates, är inte godkänd av :company.', ['what' => mb_strtolower($what), 'dates' => $dates, 'company' => $company]) }}

@if(! $approved && $reason)
{{ __('Skäl: :reason', ['reason' => $reason]) }}
@endif

@component('mail::button', ['url' => $url])
{{ __('Öppna Min sida') }}
@endcomponent
@endcomponent
