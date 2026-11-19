@php
    $input = 'block min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<x-ui.card :title="__('Registerutdrag och radering för en undertecknare')" :description="__('Sök på den exakta e-postadress som signeringslänken eller kopian gick till. Du får ett registerutdrag med det personen har signerat, och kan radera det som inte måste sparas.')" data-testid="signing-privacy">
    <form wire:submit="search" class="flex flex-wrap items-end gap-3">
        <x-ui.field for="signing-privacy-email" :label="__('E-postadress')" error="email">
            <input id="signing-privacy-email" type="email" autocomplete="off" class="{{ $input }} mt-1 w-72 max-w-full" wire:model="email" required>
        </x-ui.field>
        <x-button wire:loading.attr="disabled">{{ __('Sök') }}</x-button>
    </form>

    @if($searched !== null)
        <div class="mt-5" data-testid="signing-privacy-results">
            @if($records === [])
                <p class="text-sm text-gray-700">{{ __('Inga signeringar i företaget har gått till :email.', ['email' => $searched]) }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-600">
                                <th scope="col" class="py-2 pr-3 font-medium">{{ __('Dokument') }}</th>
                                <th scope="col" class="py-2 pr-3 font-medium">{{ __('Roll') }}</th>
                                <th scope="col" class="py-2 pr-3 font-medium">{{ __('Status') }}</th>
                                <th scope="col" class="py-2 font-medium">{{ __('Vid radering') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($records as $record)
                                @php($envelope = $record['envelope'])
                                <tr class="border-b border-gray-100 align-top" wire:key="record-{{ $envelope->id }}">
                                    <td class="py-2 pr-3">
                                        {{ $envelope->confidential ? __('Konfidentiell signering') : $envelope->title }}
                                        <span class="block text-gray-600">{{ App\Domain\Signing\Retention\SignerRecords::typeLabel($envelope->document_type) }} · {{ __('skickad :date', ['date' => $envelope->sent_at?->setTimezone($zone)->format('Y-m-d') ?? '–']) }}</span>
                                    </td>
                                    <td class="py-2 pr-3">{{ collect($record['roles'])->map(fn (string $role): string => $role === 'SIGNER' ? __('Undertecknare') : __('Kopiemottagare'))->implode(', ') }}</td>
                                    <td class="py-2 pr-3">{{ $envelope->effectiveStatus()->label() }}</td>
                                    <td class="py-2">
                                        @if($record['blocker'] === null && $record['scope'] === 'ENVELOPE')
                                            {{ __('Raderas') }}
                                        @elseif($record['blocker'] === null)
                                            {{ __('Personens uppgifter raderas. Signeringen finns kvar för de andra och dras tillbaka om den är ute.') }}
                                        @else
                                            <span class="text-gray-700">{{ App\Domain\Signing\Retention\SignerRecords::reason($record['blocker'], $envelope) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-4">
                    <form method="POST" action="{{ route('privacy.export.signer') }}">
                        @csrf
                        <input type="hidden" name="e_post" value="{{ $searched }}">
                        <input type="hidden" name="foretag" value="{{ $companyId }}">
                        <x-secondary-button type="submit">{{ __('Hämta registerutdrag') }}</x-secondary-button>
                    </form>
                    <x-danger-button type="button" wire:click="erase" wire:loading.attr="disabled" wire:confirm="{{ __('Radera allt om :email som får raderas? En signering som är ute dras tillbaka, så att ingen kan signera den längre; de andra mottagarnas uppgifter finns kvar. Signerade dokument sparas så länge lagen kräver. Det går inte att ångra.', ['email' => $searched]) }}">{{ __('Radera det som får raderas') }}</x-danger-button>
                </div>
            @endif

            @if($refusedReasons !== [])
                <p class="mt-4 text-sm text-gray-700" role="status" data-testid="signing-privacy-refused">{{ __('Det som sparas står kvar i listan, med skälet. Avslaget är loggat.') }}</p>
            @endif
        </div>
    @endif
</x-ui.card>
