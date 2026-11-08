@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-8">
    @if($firmBlocked)
        <div class="rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200" role="status">
            {{ __('Styrelsen har inte bemyndigat byrån att föra aktieboken. Ägaren eller en administratör registrerar bemyndigandet under Aktieägare, innan du kan föra in händelser.') }}
        </div>
    @endif

    @if($error)
        <div class="rounded-2xl bg-red-50 p-4 text-sm text-red-900 ring-1 ring-red-200" role="alert">{{ __('Aktieboken går inte ihop: :problem', ['problem' => $error]) }}</div>
    @elseif($state === null || ! $state->started)
        <x-ui.empty-state :title="__('Aktieboken är inte startad')">
            {{ __('Starta med bildandet om bolaget är nytt, eller för in aktieboken som den ser ut i dag om bolaget redan finns. Lägg först till aktieägarna.') }}
            @if($canManage)
                <x-slot:action>
                    <div class="flex flex-wrap justify-center gap-3">
                        <a href="{{ route('share-register.events', ['ny' => 'FORMATION']) }}" class="inline-flex items-center rounded-lg bg-accent-700 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-800">{{ __('Nytt bolag: registrera bildandet') }}</a>
                        <a href="{{ route('share-register.events', ['ny' => 'OPENING']) }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold text-accent-700 ring-1 ring-accent-300 hover:bg-accent-50">{{ __('Befintligt bolag: för in aktieboken') }}</a>
                    </div>
                </x-slot:action>
            @endif
        </x-ui.empty-state>
    @else
        <x-ui.card :title="__('Aktieboken per :date', ['date' => $day->toDateString()])">
            <x-slot:action>
                <div class="flex flex-wrap items-end gap-3">
                    <x-ui.field for="register-date" :label="__('Visa per datum')">
                        <input id="register-date" type="date" wire:model.live="date" value="{{ $day->toDateString() }}" class="{{ $input }}">
                    </x-ui.field>
                </div>
            </x-slot:action>

            <dl class="mt-4 grid gap-4 sm:grid-cols-4">
                <div><dt class="text-sm text-gray-600">{{ __('Antal aktier') }}</dt><dd class="text-lg font-semibold tabular-nums text-ink">{{ number_format($state->totalShares(), 0, ',', ' ') }}</dd></div>
                <div><dt class="text-sm text-gray-600">{{ __('Registrerat aktiekapital') }}</dt><dd class="text-lg font-semibold tabular-nums text-ink">{{ \App\Support\Money\Money::ofOre($state->capitalOre)->format() }}</dd></div>
                <div><dt class="text-sm text-gray-600">{{ __('Kvotvärde') }}</dt><dd class="text-lg font-semibold tabular-nums text-ink">{{ $state->quotaValueOre() === null ? '–' : ($state->quotaIsExact() ? '' : '≈ ').\App\Support\Money\Money::ofOre((int) $state->quotaValueOre())->format() }}</dd></div>
                <div><dt class="text-sm text-gray-600">{{ __('Ej registrerad nyemission') }}</dt><dd class="text-lg font-semibold tabular-nums text-ink">{{ \App\Support\Money\Money::ofOre($state->pendingCapitalOre())->format() }}</dd></div>
            </dl>

            <div class="mt-6 overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-600">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Aktienummer') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Antal') }}</th>
                            @if($classes->count() > 1)<th scope="col" class="py-2 pr-4 font-medium">{{ __('Aktieslag') }}</th>@endif
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Ägare') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Födelsedatum eller org.nr') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Postadress') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Aktiebrev') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Anteckningar') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($state->rows() as $row)
                            @php($snapshot = $state->snapshot($row->holderId))
                            <tr>
                                <td class="py-2 pr-4 tabular-nums">{{ $row->from }}–{{ $row->to }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row->count(), 0, ',', ' ') }}</td>
                                @if($classes->count() > 1)<td class="py-2 pr-4">{{ $classes->get($row->classId)?->code }}</td>@endif
                                <td class="py-2 pr-4 font-medium text-ink">{{ $snapshot['name'] ?? '' }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ \App\Modules\Boardroom\Models\Shareholder::identity($snapshot, true) }}</td>
                                <td class="py-2 pr-4">{{ \App\Modules\Boardroom\Models\Shareholder::address($snapshot) }}</td>
                                <td class="py-2 pr-4">{{ $row->certificate ? __('Utfärdat') : __('Nej') }}</td>
                                <td class="py-2">
                                    @if($row->pendingIssueId !== null)<x-ui.badge>{{ __('Nyemission, ej registrerad') }}</x-ui.badge>@endif
                                    @foreach($row->notes as $note)
                                        <p class="text-gray-700">{{ \App\Modules\Boardroom\Domain\ShareEventKind::from($note['kind'])->label() }} {{ $note['date'] }}: {{ $note['text'] }}</p>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('share-register.print', ['datum' => $day->toDateString(), 'version' => 'fullstandig']) }}" target="_blank" class="inline-flex items-center rounded-lg bg-accent-700 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-800">{{ __('Skriv ut aktieboken (fullständig)') }}</a>
                <a href="{{ route('share-register.print', ['datum' => $day->toDateString(), 'version' => 'utan-personnummer']) }}" target="_blank" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold text-accent-700 ring-1 ring-accent-300 hover:bg-accent-50">{{ __('Frivillig kopia utan personnummer') }}</a>
            </div>
            <p class="mt-3 text-sm text-gray-600">{{ __('Den fullständiga aktieboken är den som bolaget visar för alla som vill ta del av den (aktiebolagslagen 5 kap. 10 §). Kopian utan personnummer är frivillig och uppfyller inte den skyldigheten.') }}</p>
        </x-ui.card>

        @if($check !== null)
            <x-ui.card :title="__('Aktiekapitalet mot bokföringen')" :description="__('Registrerade aktier gånger kvotvärdet jämförs med saldot på konto 2081 Aktiekapital. NONOX bokför ingenting; en skillnad bokför du själv, eller rättar i aktieboken.')">
                @if($check->ledgerCapitalOre === null)
                    <p class="mt-4 text-sm text-gray-700">{{ __('Det finns inget räkenskapsår för datumet, så saldot på 2081 kan inte jämföras.') }}</p>
                @else
                    <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div><dt class="text-sm text-gray-600">{{ __('Aktieboken') }}</dt><dd class="font-semibold tabular-nums text-ink">{{ number_format($check->registeredShares, 0, ',', ' ') }} × {{ $check->quotaValueOre === null ? '–' : \App\Support\Money\Money::ofOre($check->quotaValueOre)->format() }} = {{ \App\Support\Money\Money::ofOre($check->registerCapitalOre)->format() }}</dd></div>
                        <div><dt class="text-sm text-gray-600">{{ __('Konto 2081') }}</dt><dd class="font-semibold tabular-nums text-ink">{{ \App\Support\Money\Money::ofOre($check->ledgerCapitalOre)->format() }}</dd></div>
                        <div><dt class="text-sm text-gray-600">{{ __('Skillnad') }}</dt><dd class="font-semibold tabular-nums {{ $check->matches() ? 'text-green-800' : 'text-red-800' }}">{{ $check->matches() ? __('Stämmer') : \App\Support\Money\Money::ofOre($check->differenceOre())->format() }}</dd></div>
                    </dl>
                    @if($check->pendingCapitalOre !== 0)
                        <p class="mt-3 text-sm text-gray-700">{{ __('En nyemission som inte är registrerad räknas inte in. Inbetalt kapital för den bokförs vanligen på 2082 tills den är registrerad.') }}</p>
                    @endif
                @endif
            </x-ui.card>
        @endif
    @endif

    <x-ui.card :title="__('Kontroll och bevarande')">
        <p class="mt-3 text-sm text-gray-700">
            @if($verified === null)
                {{ __('Aktieboken har inte kontrollerats ännu. Kedjan kontrolleras varje natt.') }}
            @elseif(($verified->payload['ok'] ?? false) === true)
                {{ __('Senast kontrollerad :time: hashkedjan är hel och ingen händelse har ändrats.', ['time' => $verified->created_at?->format('Y-m-d H:i')]) }}
            @else
                <span class="font-semibold text-red-800">{{ __('Kontrollen :time hittade avvikelser. Kontakta supporten.', ['time' => $verified->created_at?->format('Y-m-d H:i')]) }}</span>
            @endif
        </p>
        <p class="mt-2 text-sm text-gray-700">{{ __('Aktieboken bevaras så länge bolaget finns och minst tio år efter att det har upplösts. Uppgifter som har tagits bort ur aktieboken bevaras i minst tio år (aktiebolagslagen 5 kap. 3 §). Det är längre än bokföringens sju år.') }}</p>
        <p class="mt-2 text-sm text-gray-700">{{ __('Styrelsen ansvarar för att aktieboken förs, bevaras och hålls tillgänglig (aktiebolagslagen 5 kap. 7 §), även när en byrå för den.') }}</p>
    </x-ui.card>
</div>
