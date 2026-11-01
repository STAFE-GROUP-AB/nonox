{{-- "Hämta uppgifter" from the company register (ADR-035), for components using LooksUpCompanies. Hidden when no register is configured. --}}
@if($this->companyLookupAvailable())
    <div class="{{ $class ?? '' }} space-y-3 rounded-lg bg-gray-50 px-4 py-3 text-sm" data-company-lookup>
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-gray-600">{{ __('Hämta namn och adress från :source med organisationsnumret.', ['source' => $this->registrySourceName()]) }}</span>
            <button type="button" class="ml-auto font-medium text-accent-600 hover:text-accent-700" wire:click="lookUpCompany" wire:loading.attr="disabled" wire:target="lookUpCompany">
                <span wire:loading.remove wire:target="lookUpCompany">{{ __('Hämta uppgifter') }}</span>
                <span wire:loading wire:target="lookUpCompany">{{ __('Hämtar…') }}</span>
            </button>
        </div>

        @if($registryNotice !== '')
            <p role="status" class="font-medium text-accent-700">{{ $registryNotice }}</p>
        @endif

        @if($registryResult)
            <div role="status" class="space-y-1">
                <p class="text-gray-800">
                    {{ __('Enligt :source:', ['source' => $registryResult['source']]) }}
                    <span class="font-medium text-gray-900">{{ $registryResult['name'] }}</span>@if($registryResult['legal_form']) · {{ $registryResult['legal_form'] }}@endif @if($registryResult['registered_on']) · {{ __('registrerat :date', ['date' => $registryResult['registered_on']]) }}@endif
                </p>
                @if($registryResult['deregistered_on'])
                    <p class="font-medium text-accent-700">{{ __('Företaget är avregistrerat sedan :date. Kontrollera att det är rätt nummer.', ['date' => $registryResult['deregistered_on']]) }}</p>
                @endif
                @foreach($registryResult['proceedings'] as $proceeding)
                    <p class="font-medium text-accent-700">{{ __('Pågående: :what.', ['what' => $proceeding]) }}</p>
                @endforeach
                @if($registryConflicts === [])
                    <p class="text-gray-600">{{ __('Uppgifterna är ifyllda nedan och sparas först när du sparar.') }}</p>
                @endif
            </div>
        @endif

        @if($registryConflicts !== [])
            <div class="space-y-2 border-t border-gray-200 pt-3">
                <p class="text-gray-800">{{ __('Det här skiljer sig från det du har skrivit. Vill du använda registrets uppgifter?') }}</p>
                <dl class="grid gap-x-4 gap-y-1 sm:grid-cols-[auto_1fr]">
                    @foreach($registryConflicts as $key => $value)
                        <dt class="text-gray-500">{{ $this->registryLabel($key) }}</dt>
                        <dd class="font-medium text-gray-900">{{ $this->registryValueLabel($key, $value) }}</dd>
                    @endforeach
                </dl>
                <div class="flex flex-wrap items-center gap-4">
                    <button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="useRegistryValues">{{ __('Använd registrets uppgifter') }}</button>
                    <button type="button" class="text-gray-600 underline" wire:click="keepOwnValues">{{ __('Behåll mina') }}</button>
                </div>
            </div>
        @endif
    </div>
@endif
