<x-mail::message>
# {{ $heading }}

{!! $bodyMarkdown !!}

@if($buttonUrl !== '')
<x-mail::button :url="$buttonUrl">
{{ $buttonText }}
</x-mail::button>
@endif

{{ __('Med vänliga hälsningar') }}<br>
{{ $signature }}

<x-mail::subcopy>
{{ __('Det här mejlet skickades från :app, :from. Svarar du på mejlet går svaret till :reply.', ['app' => config('app.name'), 'from' => $fromAddress, 'reply' => $replyToAddress]) }}
</x-mail::subcopy>
</x-mail::message>
