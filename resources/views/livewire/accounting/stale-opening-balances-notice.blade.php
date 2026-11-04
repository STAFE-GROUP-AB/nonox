@php
    use App\Support\Money\Money;
    $m = fn (int $ore): string => Money::ofOre($ore)->format();
@endphp

<div>
    @if($shown !== [])
        <div role="status" class="space-y-3 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 print:hidden" data-stale-opening-balances>
            @foreach($shown as $stale)
                <div wire:key="stale-{{ $stale['to_id'] }}" class="space-y-2">
                    <p class="font-semibold">
                        {{ trans_choice('De ingående balanserna för :to stämmer inte längre med de utgående balanserna för :from (:count konto skiljer).|De ingående balanserna för :to stämmer inte längre med de utgående balanserna för :from (:count konton skiljer).', count($stale['accounts']), ['to' => $stale['to'], 'from' => $stale['from'], 'count' => count($stale['accounts'])]) }}
                    </p>
                    <p>{{ __('Något har bokförts eller rättats i :from efter att balanserna fördes över, till exempel en sen leverantörsfaktura eller en rättelse. NONOX ändrar inte de ingående balanserna av sig själv, eftersom det ändrar siffrorna i rapporterna för :to.', ['from' => $stale['from'], 'to' => $stale['to']]) }}</p>
                    @if(! $stale['to_open'])
                        <p>{{ __('Räkenskapsåret :label är stängt, så dess ingående balanser kan inte ändras.', ['label' => $stale['to']]) }}</p>
                    @elseif($stale['locked'])
                        <p>{{ __('Bokföringen är låst från :to:s första dag, så de ingående balanserna kan inte ändras här.', ['to' => $stale['to']]) }}</p>
                    @endif
                    <details class="group">
                        <summary class="cursor-pointer font-medium text-amber-900 underline">{{ __('Visa skillnaden per konto') }}</summary>
                        <div class="mt-2 overflow-x-auto">
                            <table class="min-w-full text-left text-sm tabular-nums">
                                <thead>
                                    <tr>
                                        <th scope="col" class="py-1 pr-4 font-medium">{{ __('Konto') }}</th>
                                        <th scope="col" class="py-1 pr-4 text-right font-medium">{{ __('Ingående balans :year', ['year' => $stale['to']]) }}</th>
                                        <th scope="col" class="py-1 pr-4 text-right font-medium">{{ __('Utgående balans :year', ['year' => $stale['from']]) }}</th>
                                        <th scope="col" class="py-1 pr-4 text-right font-medium">{{ __('Skillnad') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($stale['accounts'] as $account)
                                        <tr wire:key="stale-{{ $stale['to_id'] }}-{{ $account['number'] }}">
                                            <td class="py-1 pr-4">{{ $account['number'] }} {{ $account['name'] }}</td>
                                            <td class="py-1 pr-4 text-right">{{ $m($account['stored']) }}</td>
                                            <td class="py-1 pr-4 text-right">{{ $m($account['expected']) }}</td>
                                            <td class="py-1 pr-4 text-right">{{ $m($account['expected'] - $account['stored']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-2 text-xs">{{ __('Belopp med minus är kredit. Utgående balans räknas med ett obokat resultat fört till Årets resultat, som när balanserna förs över.') }}</p>
                    </details>
                </div>
            @endforeach

            @if($canUpdate && collect($shown)->every(fn (array $stale): bool => $stale['to_open'] && ! $stale['locked']))
                <div x-data="{ confirming: false }" class="flex flex-wrap items-center gap-3">
                    <x-button type="button" x-show="! confirming" x-on:click="confirming = true">{{ __('Uppdatera ingående balanser') }}</x-button>
                    <div x-show="confirming" x-cloak class="flex flex-wrap items-center gap-3">
                        <span>{{ __('De ingående balanserna för :years ersätts med de utgående balanserna för året före. Balansrapporten och huvudboken för :years ändras. Inga verifikationer ändras, och ändringen sparas i behandlingshistoriken.', ['years' => $years]) }}</span>
                        <x-button type="button" wire:click="update" x-on:click="confirming = false" wire:loading.attr="disabled">{{ __('Ja, uppdatera') }}</x-button>
                        <button type="button" class="text-amber-900 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                    </div>
                </div>
            @elseif(! $canUpdate)
                <p>{{ __('Be någon som får hantera räkenskapsår att uppdatera dem.') }}</p>
            @endif
        </div>
    @endif
</div>
