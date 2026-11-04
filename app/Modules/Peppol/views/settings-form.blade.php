@php($input = 'mt-1 block w-full max-w-xs border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs tabular-nums')
<div class="space-y-5">
    <form wire:submit="save" class="space-y-5">
        <x-ui.field for="peppol-identifier" :label="__('Företagets Peppol-ID')" :hint="__('Företagets organisationsnummer med 0007 framför, till exempel 0007:5599000006. En enskild firma kan använda ett GLN (0088) i stället för personnumret.')" error="identifier">
            <input id="peppol-identifier" type="text" wire:model="identifier" class="{{ $input }}" autocomplete="off" spellcheck="false">
        </x-ui.field>
        <label class="flex items-start gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model="receive" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" @disabled(! $inboxOn)>
            <span>{{ __('Ta emot leverantörsfakturor via Peppol') }}<br><span class="text-gray-600">{{ $inboxOn ? __('E-fakturorna hamnar i inkorgen och blir förslag till leverantörsfakturor som någon attesterar.') : __('Slå på Inkorg först. Där attesteras e-fakturorna.') }}</span></span>
        </label>
        <x-button type="submit">{{ __('Spara') }}</x-button>
    </form>

    <div class="rounded-lg border border-gray-200 p-4 text-sm text-gray-700">
        <p class="font-semibold text-ink">{{ __('Registrering i Peppol') }}</p>
        <dl class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
            <div><dt class="text-gray-500">{{ __('Status') }}</dt><dd>{{ ($participant?->registration_status ?? App\Modules\Peppol\Domain\RegistrationStatus::NotRegistered)->label() }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Konto hos Peppol-operatören') }}</dt><dd>{{ $participant?->provider_account !== null ? __('Kopplat') : __('Inte kopplat än') }}</dd></div>
            @if($participant?->consent_at)
                <div><dt class="text-gray-500">{{ __('Godkänt') }}</dt><dd class="tabular-nums">{{ $participant->consent_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</dd></div>
            @endif
        </dl>
        @if($participant?->last_error)
            <p class="mt-2 text-red-700">{{ __('Senaste felet från Peppol-operatören: :error', ['error' => $participant->last_error]) }}</p>
        @endif
        <p class="mt-3 text-gray-600">{{ __('För att kunna ta emot e-fakturor måste företaget finnas i Peppols adressbok. Peppol-operatören kontrollerar först vem företaget är och registrerar det sedan. Det behövs inte för att bara skicka.') }}</p>
        <form wire:submit="register" class="mt-3 space-y-3">
            <label class="flex items-start gap-2">
                <input type="checkbox" wire:model="consent" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500">
                <span>{{ __('Jag har rätt att företräda företaget och ger Peppol-operatören i uppdrag att registrera företaget som mottagare av e-fakturor med Peppol-ID:t ovan. Företagets namn, land och Peppol-ID blir då sökbara i Peppols öppna adressbok. Registreringen tas bort när mottagningen stängs av.') }}</span>
            </label>
            <x-input-error for="identifier" />
            <x-secondary-button type="submit">{{ __('Registrera som mottagare') }}</x-secondary-button>
        </form>
    </div>
</div>
