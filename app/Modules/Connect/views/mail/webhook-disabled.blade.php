<x-mail::message>
# {{ __('En webhook har stängts av') }}

{{ __('NONOX har slutat skicka händelser för :company till :url, eftersom mottagaren inte har tagit emot dem på ungefär ett dygn.', ['company' => $companyName, 'url' => $url]) }}

@if($description)
{{ __('Beskrivning: :description', ['description' => $description]) }}
@endif

{{ __('Det sista försöket gällde :event, och :answer.', ['event' => $eventType, 'answer' => $lastAnswer]) }}

{{ __('Händelserna som väntade har inte skickats. Bokföringen påverkas inte.') }}

{{ __('När mottagaren fungerar igen slår du på webhooken under Anslutningar. Nya händelser skickas från och med då.') }}

{{ config('app.name') }}
</x-mail::message>
