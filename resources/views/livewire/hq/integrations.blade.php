@php
    $areas = [
        'banking' => ['title' => __('Bank'), 'rows' => [
            __('Aktiva bankkopplingar') => $status['banking']['active'],
            __('Försöker igen efter fel') => $status['banking']['retrying'],
            __('Misslyckas upprepat') => $status['banking']['failing'],
            __('Medgivanden som går ut inom 14 dagar') => $status['banking']['expiring'],
            __('Senaste lyckade hämtning') => $status['banking']['last_fetch'] ?? '–',
        ]],
        'peppol' => ['title' => __('Peppol'), 'rows' => [
            __('Företag kopplade till operatören') => $status['peppol']['linked'],
            __('Företag med fel från operatören') => $status['peppol']['participant_errors'],
            __('E-fakturor i kö') => $status['peppol']['queued'],
            __('Väntar på att pausen tas bort') => $status['peppol']['waiting_for_pause'],
            __('Misslyckade sändningar senaste veckan') => $status['peppol']['failed_week'],
            __('Händelser som inte gick att hantera senaste veckan') => $status['peppol']['events_failed_week'],
        ]],
    ];
@endphp
<div class="grid gap-6 lg:grid-cols-2">
    @foreach($areas as $key => $area)
        @php($values = $status[$key])
        <x-ui.card :title="$area['title']">
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500">{{ __('Leverantör') }}</dt><dd class="font-medium text-ink">{{ $values['driver'] === '' ? __('Ingen') : $values['driver'] }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Läge') }}</dt>
                    <dd class="font-medium">
                        @if($values['paused'])
                            <x-ui.badge tone="accent">{{ __('Pausad') }}</x-ui.badge>
                        @elseif($values['configured'])
                            <x-ui.badge tone="success">{{ __('Inställd') }}</x-ui.badge>
                        @else
                            <x-ui.badge>{{ __('Inte inställd') }}</x-ui.badge>
                        @endif
                    </dd>
                </div>
                @foreach($area['rows'] as $label => $value)
                    <div><dt class="text-gray-500">{{ $label }}</dt><dd class="tabular-nums text-ink">{{ $value }}</dd></div>
                @endforeach
            </dl>

            @if($values['problems'] !== [])
                <ul class="mt-4 space-y-1 text-sm text-accent-700" role="status">
                    @foreach($values['problems'] as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            @else
                <p class="mt-4 text-sm text-green-800">{{ __('Inget behöver åtgärdas.') }}</p>
            @endif

            <p class="mt-4 text-sm text-gray-600">{{ __('Nödstopp: sätt :env=true i miljön (eller en lista med leverantörer) och läs om konfigurationen. Nycklarna ligger kvar. Ta bort värdet för att slå på igen.', ['env' => $values['pause_env']]) }}</p>
        </x-ui.card>
    @endforeach
</div>
