<x-mail::message>
# {{ __('Nya i kön') }}

{{ trans_choice('{1} En ny anmälan det senaste dygnet:|[2,*] :count nya anmälningar det senaste dygnet:', count($companies), ['count' => count($companies)]) }}

@foreach($companies as $company)
- {{ $company }}
@endforeach

{{ __('Totalt väntar :count på beslut.', ['count' => $pending]) }}

<x-mail::button :url="$url">
{{ __('Öppna kön i HQ') }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
