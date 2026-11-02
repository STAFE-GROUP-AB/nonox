@component('mail::message')
@if($kind === App\Modules\Planning\Notifications\ScheduleNotice::CANCELLED)
# {{ __('Ett pass är inställt') }}

{{ __(':company har ställt in det här passet:', ['company' => $company]) }}
@else
# {{ __('Ditt schema för :period', ['period' => $period]) }}

{{ __(':company har publicerat schemat. Dina pass:', ['company' => $company]) }}
@endif

@forelse($shifts as $shift)
- **{{ $shift['day'] }}** {{ $shift['time'] }}@if($shift['area']) · {{ $shift['area'] }}@endif @if($shift['change'] !== '') ({{ $shift['change'] }})@endif

@empty
{{ __('Du har inga pass under perioden.') }}
@endforelse

@component('mail::button', ['url' => route('payroll.portal.home')])
{{ __('Se schemat på Min sida') }}
@endcomponent

{{ __('Frågor om schemat ställer du till din chef.') }}
@endcomponent
