<form wire:submit="save" class="space-y-5">
    <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="minimumWageWarnings">
        <span>
            <span class="font-medium text-gray-900">{{ __('Varna när lönen är under avtalets minimilön') }}</span>
            <span class="block text-gray-600">{{ __('Varningen visas på lönekörningen per anställd, med lönegrupp, ålder, branschvana och källa. Lönen ändras aldrig automatiskt.') }}</span>
        </span>
    </label>
    <p class="text-sm text-gray-600">{{ __('Vilket avtal företaget är bundet av, och varje anställds lönegrupp och branschvana, anges under Lön → Avtal.') }}
        @if(Route::has('agreements.index'))
            <a href="{{ route('agreements.index') }}" class="font-semibold text-accent-700 underline">{{ __('Öppna Avtal') }}</a>
        @endif
    </p>
    <x-button type="submit">{{ __('Spara') }}</x-button>
</form>
