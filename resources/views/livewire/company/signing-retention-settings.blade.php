@php
    $input = 'block min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<x-ui.card :title="__('Bevarande av signeringar')" :description="__('Hur länge signerade och osignerade dokument sparas innan de gallras. Gallringen körs varje natt och tar bort hela signeringen med dokument, signeringsbevis och händelser.')" data-testid="signing-retention-settings">
    <ul class="space-y-2 text-sm text-gray-700">
        <li><span class="font-medium text-gray-900">{{ __('Räkenskapsinformation') }}</span> – {{ __('signerade offerter, avtal och ÄTA. Sparas minst till och med 31 december sju år efter slutet av det räkenskapsår då affärshändelsen bokfördes (bokföringslagen 7 kap. 2 §), och minst lika länge som andra signerade dokument. Ett avtal gallras inte medan det gäller. Tiden kan inte kortas.') }}</li>
        <li><span class="font-medium text-gray-900">{{ __('Andra signerade dokument') }}</span> – {{ __('egna dokument som har signerats. Sparas så många år efter signeringen som du väljer nedan.') }}</li>
        <li><span class="font-medium text-gray-900">{{ __('Inte signerade') }}</span> – {{ __('avböjda, tillbakadragna och utgångna. Sparas i :months månader efter att de stängdes, även om någon hann signera.', ['months' => $unsignedMonths]) }}</li>
        <li><span class="font-medium text-gray-900">{{ __('Uppladdade men aldrig skickade') }}</span> – {{ __('tas bort :days dagar efter uppladdningen.', ['days' => $unsentDays]) }}</li>
    </ul>

    <form wire:submit="save" class="mt-5 flex flex-wrap items-end gap-3" data-testid="signing-retention-form">
        <x-ui.field for="signing-retention-years" :label="__('Bevarandetid för andra signerade dokument (år)')" error="years" :hint="__('Mellan :min och :max år. Standard är tio år, den allmänna preskriptionstiden.', ['min' => $min, 'max' => $max])">
            <input id="signing-retention-years" type="number" inputmode="numeric" min="{{ $min }}" max="{{ $max }}" step="1" class="{{ $input }} mt-1 w-28" wire:model="years" required>
        </x-ui.field>
        <x-button wire:loading.attr="disabled">{{ __('Spara bevarandetid') }}</x-button>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[28rem] text-sm" data-testid="signing-retention-classes">
            <thead>
                <tr class="border-b border-gray-200 text-left text-gray-600">
                    <th scope="col" class="py-2 pr-3 font-medium">{{ __('Bevarandeklass') }}</th>
                    <th scope="col" class="py-2 pr-3 text-right font-medium">{{ __('Signeringar') }}</th>
                    <th scope="col" class="py-2 font-medium">{{ __('Första kan gallras') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($kinds as $kind)
                    @php($row = $classes->get($kind->value))
                    <tr class="border-b border-gray-100">
                        <td class="py-2 pr-3">{{ $kind->label() }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums">{{ $row?->getAttribute('envelopes') ?? 0 }}</td>
                        <td class="py-2 tabular-nums">{{ $row?->getAttribute('first_until') ? \Carbon\CarbonImmutable::parse((string) $row->getAttribute('first_until'))->addDay()->toDateString() : '–' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>


    <h3 class="mt-6 text-base font-semibold text-gray-900">{{ __('Bevarandespärrar') }}</h3>
    <p class="mt-1 text-sm text-gray-600">{{ __('En signering med bevarandespärr gallras inte och kan inte raderas, till exempel under en skatterevision eller en tvist. Sök fram signeringen här, välj skäl och sätt spärren. Det fungerar för offerter, avtal och ÄTA även utan modulen Signering.') }}</p>

    @if($held->isEmpty())
        <p class="mt-3 text-sm text-gray-700" data-testid="signing-holds-empty">{{ __('Ingen signering har bevarandespärr.') }}</p>
    @else
        <ul class="mt-3 divide-y divide-gray-100 text-sm" data-testid="signing-holds">
            @foreach($held as $envelope)
                <li class="flex flex-wrap items-baseline justify-between gap-2 py-2" wire:key="hold-{{ $envelope->id }}">
                    <span class="font-medium text-gray-900">{{ $envelope->confidential && ! $seesConfidential && $envelope->created_by !== $userId ? __('Konfidentiell signering') : $envelope->title }}</span>
                    <span class="text-gray-600">
                        {{ App\Domain\Documents\LegalHoldReason::tryFrom((string) $envelope->retention_hold_reason)?->label() }} · {{ __('sedan :date', ['date' => $envelope->retention_hold_at?->setTimezone($zone)->format('Y-m-d')]) }}
@if(! $envelope->confidential || $seesConfidential || $envelope->created_by === $userId)
                        <button type="button" class="ms-2 min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="liftHold('{{ $envelope->id }}')" wire:confirm="{{ __('Häva bevarandespärren? Signeringen gallras igen när bevarandetiden har gått ut.') }}">{{ __('Häv bevarandespärr') }}</button>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit="searchHolds" class="mt-5 flex flex-wrap items-end gap-3" data-testid="signing-hold-search">
        <x-ui.field for="signing-hold-search" :label="__('Sök signering att spärra')" error="holdSearch" :hint="__('Sök på rubriken, till exempel offertens nummer eller avtalets namn.')">
            <input id="signing-hold-search" type="search" class="{{ $input }} mt-1 w-72 max-w-full" wire:model="holdSearch" required>
        </x-ui.field>
        <x-secondary-button type="submit">{{ __('Sök') }}</x-secondary-button>
    </form>

    @if($searchedHolds !== null)
        @if($found->isEmpty())
            <p class="mt-3 text-sm text-gray-700">{{ __('Ingen signering utan bevarandespärr har en rubrik som innehåller ”:search”.', ['search' => $searchedHolds]) }}</p>
        @else
            <div class="mt-3">
                <x-ui.field for="signing-hold-reason" :label="__('Skäl för bevarandespärr')" error="holdReason">
                    <select id="signing-hold-reason" class="{{ $input }} mt-1" wire:model="holdReason">
                        <option value="">{{ __('Välj skäl') }}</option>
                        @foreach($reasons as $reason)
                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            </div>
            <ul class="mt-3 divide-y divide-gray-100 text-sm" data-testid="signing-hold-results">
                @foreach($found as $envelope)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 py-2" wire:key="found-{{ $envelope->id }}">
                        <span>
                            <span class="font-medium text-gray-900">{{ $envelope->title }}</span>
                            <span class="block text-gray-600">{{ App\Domain\Signing\Retention\SignerRecords::typeLabel($envelope->document_type) }} · {{ $envelope->effectiveStatus()->label() }}@if($envelope->retention_until) · {{ __('sparas till och med :date', ['date' => $envelope->retention_until->toDateString()]) }}@endif</span>
                        </span>
                        <x-secondary-button type="button" wire:click="setHold('{{ $envelope->id }}')">{{ __('Sätt bevarandespärr') }}</x-secondary-button>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</x-ui.card>
