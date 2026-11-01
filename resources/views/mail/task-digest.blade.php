@component('mail::message')
# {{ __('God morgon, :name', ['name' => $userName]) }}

{{ __('Det här väntar på dig i NONOX.') }}

@foreach($tasks as $task)
- **{{ $task['title'] }}** · {{ $task['company'] }} · {{ $task['status'] }}@if($task['due']) · {{ $task['overdue'] ? __('försenad, sista dag var :date', ['date' => $task['due']]) : __('sista dag :date', ['date' => $task['due']]) }}@endif

@endforeach

@component('mail::button', ['url' => $url])
{{ __('Öppna Uppgifter') }}
@endcomponent

{{ __('Du får det här mejlet för att du har valt en daglig sammanfattning. Du stänger av den på sidan Uppgifter.') }}
@endcomponent
