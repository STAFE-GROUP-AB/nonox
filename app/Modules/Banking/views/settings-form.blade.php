@php($input = 'mt-1 block w-32 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs tabular-nums')
<form wire:submit="save" class="space-y-5">
    <x-ui.field for="banking-fetches" :label="__('Hämtningar per dag')" :hint="__('1 till 4. En bank får begränsa hämtningar utan att du är inloggad till fyra per dag. Du kan alltid hämta för hand under Bank.')" error="settings.fetches_per_day">
        <input id="banking-fetches" type="number" min="1" max="4" wire:model="settings.fetches_per_day" class="{{ $input }}">
    </x-ui.field>
    <label class="flex items-center gap-2 text-sm text-gray-700">
        <input type="checkbox" wire:model="settings.reconsent_reminders" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500">
        {{ __('Påminn med e-post när ett medgivande snart går ut') }}
    </label>
    <x-ui.field for="banking-remind" :label="__('Påminn så här många dagar före')" :hint="__('Påminnelsen går till alla som får hantera Bank, och syns under Att göra på Översikt.')" error="settings.reconsent_reminder_days">
        <input id="banking-remind" type="number" min="1" max="60" wire:model="settings.reconsent_reminder_days" class="{{ $input }}">
    </x-ui.field>

    <div class="text-sm text-gray-700">
        <p class="font-semibold text-ink">{{ __('Kopplade banker') }}</p>
        @if($connections->isEmpty())
            <p class="mt-1 text-gray-600">{{ __('Ingen bank är kopplad ännu.') }}</p>
        @else
            <ul class="mt-1 list-disc pl-5">
                @foreach($connections as $connection)
                    <li>{{ $connection->institution_name }}: {{ $connection->status->label() }}@if($connection->valid_until !== null), {{ __('till och med :date', ['date' => $connection->valid_until->toDateString()]) }}@endif</li>
                @endforeach
            </ul>
            <ul class="mt-2 list-disc pl-5">
                @foreach($accounts as $account)
                    <li>{{ $account->label() }} → {{ $account->ledgerAccount === null ? __('hämtas inte') : $account->ledgerAccount->number.' '.$account->ledgerAccount->name }}</li>
                @endforeach
            </ul>
        @endif
        @if(Route::has('banking.index'))
            <a href="{{ route('banking.index') }}" class="mt-2 inline-block font-semibold text-accent-700 underline">{{ __('Koppla banker och välj konton under Bank') }}</a>
        @endif
    </div>

    <x-button type="submit">{{ __('Spara') }}</x-button>
</form>
