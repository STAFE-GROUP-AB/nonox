@component('mail::message')
# {{ $sentence }}

@foreach($shifts as $shift)
- **{{ $shift['day'] }}** {{ $shift['time'] }}@if($shift['area']) · {{ $shift['area'] }}@endif @if($shift['change'] !== '') ({{ $shift['change'] }})@endif

@endforeach
@if($reason)
{{ __('Meddelande från :company: :reason', ['company' => $company, 'reason' => $reason]) }}
@endif

@component('mail::button', ['url' => route('payroll.portal.home')])
{{ __('Öppna Min sida') }}
@endcomponent
@endcomponent
