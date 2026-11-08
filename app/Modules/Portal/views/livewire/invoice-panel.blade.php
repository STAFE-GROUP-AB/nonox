@php($zone = config('app.timezone'))
@php($at = fn ($value) => $value === null ? null : Carbon\CarbonImmutable::parse($value)->setTimezone($zone)->format('Y-m-d H:i'))

<x-ui.card :title="__('Kundportal')" data-testid="invoice-portal-panel">
    @php($openDispute = $disputes->firstWhere('status', 'OPEN'))
    @if($openDispute)
        <p class="mt-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200" role="alert">
            {{ __('Kunden bestrider fakturan. Läs kundens meddelande och svara innan du skickar en påminnelse.') }}
            <a href="{{ route('portal.admin.questions', ['trad' => $openDispute->id]) }}" class="font-semibold underline">{{ __('Öppna bestridandet') }}</a>
        </p>
    @endif

    <dl class="mt-3 space-y-2 text-sm">
        <div>
            <dt class="inline text-gray-600">{{ __('Öppnad i kundportalen:') }}</dt>
            <dd class="inline">{{ $firstOpened ? $at($firstOpened) : __('Inte ännu') }}</dd>
        </div>
        @foreach($notes as $note)
            <div>
                <dt class="inline text-gray-600">{{ __('Kunden har meddelat att den är betald:') }}</dt>
                <dd class="inline">{{ __(':date (meddelat :when). Inget är bokfört; registrera betalningen när den har kommit in.', ['date' => $note->paid_on->toDateString(), 'when' => $at($note->created_at)]) }}</dd>
            </div>
        @endforeach
    </dl>

    @if($events->isNotEmpty())
        <details class="mt-3 text-sm">
            <summary class="cursor-pointer font-medium">{{ __('Händelser i kundportalen') }}</summary>
            <ul class="mt-2 space-y-1">
                @foreach($events as $event)
                    <li>{{ $at($event->created_at) }} · {{ $event->event->label() }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</x-ui.card>
