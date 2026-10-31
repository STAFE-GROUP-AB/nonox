@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
@endphp

<div class="space-y-6" @if($building) wire:poll.5s @endif>
    <x-ui.card :title="__('Dina räkenskapsår')">
        @if($canManage)
            <x-slot:action>
                <x-button type="button" wire:click="createNext" wire:loading.attr="disabled">{{ __('Skapa nästa räkenskapsår') }}</x-button>
            </x-slot:action>
        @endif

        <ul class="divide-y divide-gray-100">
            @foreach($fiscalYears as $fiscalYear)
                <li wire:key="fiscal-year-{{ $fiscalYear->id }}" class="py-4">
                    @if($editingId === $fiscalYear->id)
                        <form wire:submit="save" class="space-y-4">
                            <p class="text-sm text-gray-600">{{ __('Började företaget mitt i ett år kan det första räkenskapsåret vara kortare eller längre än tolv månader, upp till arton månader. Det börjar den första och slutar den sista dagen i en månad.') }}</p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-ui.field for="start_date" :label="__('Första dag')">
                                    <input id="start_date" type="date" class="{{ $input }} mt-1" wire:model="startDate">
                                </x-ui.field>
                                <x-ui.field for="end_date" :label="__('Sista dag')">
                                    <input id="end_date" type="date" class="{{ $input }} mt-1" wire:model="endDate">
                                </x-ui.field>
                            </div>
                            <div class="flex items-center gap-4">
                                <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                                <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelEditing">{{ __('Avbryt') }}</button>
                            </div>
                        </form>
                    @else
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold text-gray-900">
                                    {{ $fiscalYear->label() }}
                                    @if($fiscalYear->contains($today))
                                        <x-ui.badge tone="accent" class="ms-2">{{ __('Pågår nu') }}</x-ui.badge>
                                    @endif
                                </p>
                                <p class="text-sm text-gray-600 tabular-nums">{{ $fiscalYear->start_date->toDateString() }} – {{ $fiscalYear->end_date->toDateString() }} · {{ $fiscalYear->status->label() }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-4">
                                @if($canExport)
                                    <a href="{{ route('accounting.fiscalYears.sie', $fiscalYear) }}" class="text-sm font-medium text-accent-600 hover:text-accent-700" title="{{ __('Hela bokföringen för året i SIE4-format, som alla svenska ekonomiprogram kan läsa.') }}">{{ __('Ladda ner SIE-fil') }}</a>
                                @endif
                                @if($canArchive)
                                    @php($package = $packages->get($fiscalYear->id))
                                    @if($package?->isBusy())
                                        <span class="text-sm text-gray-600">{{ __('Arkivpaketet skapas…') }}</span>
                                    @else
                                        @if($package?->status === 'DONE')
                                            <a href="{{ route('accounting.fiscalYears.archive', [$fiscalYear, $package]) }}" class="text-sm font-medium text-accent-600 hover:text-accent-700" title="{{ __('Skapat :date, :files filer.', ['date' => $package->completed_at?->setTimezone('Europe/Stockholm')->format('Y-m-d H:i'), 'files' => $package->file_count]) }}">{{ __('Ladda ner arkivpaket (:size)', ['size' => Illuminate\Support\Number::fileSize((int) $package->size, precision: 1)]) }}</a>
                                        @elseif($package?->status === 'FAILED')
                                            <span class="text-sm text-accent-800">{{ $package->error }}</span>
                                        @endif
                                        <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="createArchive({{ $fiscalYear->id }})" wire:loading.attr="disabled" title="{{ __('En ZIP-fil med årets SIE-fil, rapporter, underlag, fakturor, momsdeklarationer, lönebesked, behandlingshistorik och systemdokumentation, med en kontrollsumma för varje fil.') }}">{{ $package?->status === 'DONE' ? __('Skapa nytt arkivpaket') : __('Skapa arkivpaket') }}</button>
                                    @endif
                                @endif
                                @if($canManage)
                                    @php($nextLabel = $nextLabels[$fiscalYear->id])
                                    <span x-data="{ confirming: false }" class="inline-flex flex-wrap items-center gap-3">
                                        <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" x-show="! confirming" x-on:click="confirming = true">{{ __('För över balanser till :year', ['year' => $nextLabel]) }}</button>
                                        <span x-show="confirming" x-cloak class="inline-flex flex-wrap items-center gap-3 text-sm">
                                            <span class="text-gray-700">{{ __('Ingående balanser för :year ersätts med utgående balanser för :from.', ['year' => $nextLabel, 'from' => $fiscalYear->label()]) }}</span>
                                            <button type="button" class="font-semibold text-accent-600 hover:text-accent-700" wire:click="carryForward({{ $fiscalYear->id }})" x-on:click="confirming = false">{{ __('Ja, för över') }}</button>
                                            <button type="button" class="text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                                        </span>
                                    </span>
                                @endif
                                @if($canManage && $onlyYearId === $fiscalYear->id && $fiscalYear->isOpen())
                                    <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="startEditing({{ $fiscalYear->id }})">{{ __('Ändra datum') }}</button>
                                @endif
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    <x-input-error for="fiscal_year" />
    <p class="text-sm text-gray-500">{{ __('Vid årsskiftet förs utgående balanser över som ingående balanser till nästa år. Ett resultat som inte är bokfört förs till Årets resultat. Har du bokfört något i det gamla året efteråt, för över igen. Ett räkenskapsår stängs under Bokslut.') }}</p>
    @if($canArchive)
        <p class="text-sm text-gray-500">{{ __('Arkivpaketet är en ZIP-fil med allt som räknas som räkenskapsinformation för året, i format som går att läsa utan NONOX. Bokföringslagen kräver att den sparas i sju år efter utgången av det kalenderår då räkenskapsåret avslutades. Ladda ner paketet efter bokslutet och spara det på ett säkert ställe.') }}</p>
    @endif
    @if($canExport)
        <p class="text-sm text-gray-500">{{ __('SIE-filen innehåller hela årets bokföring: kontoplan, ingående och utgående balanser och alla bokförda verifikationer. Din revisor eller redovisningsbyrå kan läsa in den i sitt program, och du kan alltid ta med dig bokföringen härifrån.') }}</p>
    @endif
</div>
