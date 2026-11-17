@component('mail::message')
# {{ __('Nytt meddelande om schemat') }}

{{ __(':company har skickat ett meddelande till dig om schemat.', ['company' => $company]) }}
@if($requiresAck)
{{ __('Du ombeds bekräfta att du har läst det.') }}
@endif

{{ __('Av integritetsskäl står meddelandet inte i e-postbrevet. Du läser det när du har loggat in på Min sida.') }}

{{ __('När du öppnar meddelandet ser din chef att du har läst det, och när.') }}

@component('mail::button', ['url' => route('payroll.portal.home')])
{{ __('Läs på Min sida') }}
@endcomponent

{{ __('Frågor om schemat ställer du till din chef.') }}
@endcomponent
