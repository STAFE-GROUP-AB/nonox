@component('mail::message')
# {{ __('Utskicken i :company är pausade', ['company' => $companyName]) }}

{{ $reason === 'COMPLAINTS'
    ? __('Fler än 0,3 procent av mottagarna har anmält era utskick som skräppost de senaste 30 dagarna. NONOX har pausat utskicken så att e-posttjänsten inte spärras.')
    : __('Fler än 5 procent av adresserna i era utskick studsade de senaste 30 dagarna. NONOX har pausat utskicken så att e-posttjänsten inte spärras.') }}

{{ __('Gå igenom segmenten och samtyckena, och återuppta under Utskick när orsaken är åtgärdad.') }}

@component('mail::button', ['url' => $url])
{{ __('Öppna Utskick') }}
@endcomponent
@endcomponent
