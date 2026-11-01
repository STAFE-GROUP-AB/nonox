@props(['company'])

{{-- An archived company can only be read (ADR-024); every page says so. --}}
@if($company?->isArchived())
    <div role="status" class="border-b border-stone-300 bg-stone-100 px-4 py-3 text-sm text-gray-800 sm:px-6 lg:px-10">
        <span class="font-semibold">{{ __('Företaget är arkiverat och kan bara läsas.') }}</span>
        {{ __('Allt finns kvar och kan exporteras, men inget kan bokföras eller ändras.') }}
        @can('unarchive', $company)
            <a href="{{ route('teams.show', ['company' => $company->id, 'flik' => 'agarskap']) }}" class="font-medium text-accent-700 underline">{{ __('Ta ur arkivet') }}</a>
        @endcan
    </div>
@endif
