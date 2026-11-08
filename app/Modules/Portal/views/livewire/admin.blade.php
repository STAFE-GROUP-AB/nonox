@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($zone = config('app.timezone'))

<div class="space-y-6">
    <x-ui.card :title="__('Kontakter med tillgång')" :description="__('Bjud in kontakter från kundkortet under Kunder. Här ser du alla som har tillgång till kundportalen.')">
        @if($contacts->isEmpty())
            <x-ui.empty-state class="mt-4" :title="__('Ingen har tillgång ännu')">
                {{ __('Öppna en kund under Kunder och välj Bjud in i rutan Kundportal.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm">
                    <caption class="sr-only">{{ __('Kontakter med tillgång') }}</caption>
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-600">
                            <th scope="col" class="py-2 pr-3 font-normal">{{ __('Kund') }}</th>
                            <th scope="col" class="py-2 pr-3 font-normal">{{ __('Kontakt') }}</th>
                            <th scope="col" class="py-2 pr-3 font-normal">{{ __('Status') }}</th>
                            <th scope="col" class="py-2 pr-3 font-normal">{{ __('Senast inne') }}</th>
                            <th scope="col" class="py-2 font-normal"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contacts as $contact)
                            <tr wire:key="admin-contact-{{ $contact->id }}" class="border-b border-gray-100">
                                <td class="py-2 pr-3">{{ $contact->customer?->number }} {{ $contact->customer?->name }}</td>
                                <td class="py-2 pr-3">{{ $contact->identity?->name }}<br><span class="text-gray-600">{{ $contact->identity?->email }}</span></td>
                                <td class="py-2 pr-3">{{ $contact->status->label() }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ $contact->last_seen_at?->setTimezone($zone)->format('Y-m-d') ?? '–' }}</td>
                                <td class="py-2 text-right">
                                    @if($canManage)
                                        @if($contact->status->value !== 'DISABLED')
                                            <button type="button" class="font-medium text-gray-700 underline" wire:click="disable({{ $contact->id }})" wire:confirm="{{ __('Stänga av :name? Personen loggas ut direkt och kan inte logga in igen förrän du bjuder in igen.', ['name' => $contact->identity?->name]) }}">{{ __('Stäng av') }}</button>
                                        @endif
                                        <button type="button" class="ms-3 font-medium text-red-700 underline" wire:click="remove({{ $contact->id }})" wire:confirm="{{ __('Ta bort :name från kundportalen? Inloggningen tas bort. Meddelanden finns kvar med avsändaren ”Borttagen kontakt”.', ['name' => $contact->identity?->name]) }}">{{ __('Ta bort') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $contacts->links() }}</div>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Händelser i kundportalen')" :description="__('De senaste inloggningarna och vad kunderna har öppnat. Sparas i 24 månader.')">
        @if($events->isEmpty())
            <p class="mt-4 text-sm text-gray-600">{{ __('Inga händelser ännu.') }}</p>
        @else
            <ul class="mt-4 space-y-1 text-sm">
                @foreach($events as $event)
                    <li><span class="tabular-nums">{{ $event->created_at->setTimezone($zone)->format('Y-m-d H:i') }}</span> · {{ $event->contact?->identity?->name ?? __('Borttagen kontakt') }} · {{ $event->event->label() }}</li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Inställningar')">
        <p class="mt-2 text-sm text-gray-600">{{ __('Kundportalens adress: :url', ['url' => route('portal.home', ['portalKey' => $settings->portal_key])]) }}</p>
        <form wire:submit="saveSettings" class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="flex items-start gap-3 text-sm sm:col-span-2">
                <input type="checkbox" wire:model="showPoweredBy" class="mt-0.5 rounded border-gray-400" @disabled(! $canManage)>
                <span>{{ __('Visa ”Kundportalen drivs med NONOX” längst ned i kundportalen') }}</span>
            </label>
            <x-ui.field for="portal-history" :label="__('Visa fakturor från de senaste (månader)')" error="history_months">
                <input id="portal-history" type="number" min="3" max="120" class="{{ $input }} mt-1" wire:model="historyMonths" @disabled(! $canManage)>
            </x-ui.field>
            <p class="text-sm text-gray-600 sm:pt-6">{{ __('Obetalda fakturor visas alltid, hur gamla de än är.') }}</p>
            <x-ui.field for="portal-privacy" :label="__('Egen text om personuppgifter (frivillig)')" error="privacy_notice" class="sm:col-span-2">
                <textarea id="portal-privacy" rows="4" maxlength="4000" class="{{ $input }} mt-1" wire:model="privacyNotice" @disabled(! $canManage)></textarea>
            </x-ui.field>
            @if($canManage)
                <div class="sm:col-span-2"><x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button></div>
            @endif
        </form>
    </x-ui.card>
</div>
