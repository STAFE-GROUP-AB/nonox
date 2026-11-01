<x-action-section>
    <x-slot name="title">{{ __('Radera mitt konto') }}</x-slot>
    <x-slot name="description">{{ __('Ta bort ditt konto och dina uppgifter i NONOX.') }}</x-slot>
    <x-slot name="content">
        <div class="max-w-xl space-y-3 text-sm text-gray-600">
            <p>{{ __('Ditt namn, din e-postadress, dina inloggningar och dina medlemskap i företag tas bort, och du kan inte logga in med kontot igen.') }}</p>
            <p>{{ __('Det du har bokfört, attesterat eller ändrat finns kvar i företagens bokföring och behandlingshistorik, eftersom bokföringslagen kräver att det sparas. Där står det då "Raderad användare" i stället för ditt namn.') }}</p>
            <p>{{ __('Vill du spara dina uppgifter, ladda ner dem under Mina uppgifter innan du raderar kontot.') }}</p>
        </div>

        <x-input-error for="account" class="mt-3" />

        @if($ownedCompanies !== [])
            <p class="mt-4 max-w-xl rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
                {{ __('Du är ägare till :companies. Överlåt ägarskapet till någon annan eller arkivera företaget innan du raderar ditt konto.', ['companies' => implode(', ', $ownedCompanies)]) }}
            </p>
        @else
            <div class="mt-5">
                <x-danger-button wire:click="confirmDeletion" wire:loading.attr="disabled">
                    {{ __('Radera mitt konto') }}
                </x-danger-button>
            </div>
        @endif

        <x-dialog-modal wire:model.live="confirming">
            <x-slot name="title">{{ __('Radera ditt konto?') }}</x-slot>

            <x-slot name="content">
                <ul class="list-disc space-y-1 ps-5 text-sm text-gray-600">
                    <li>{{ __('Du loggas ut på alla enheter och kan inte logga in med kontot igen.') }}</li>
                    <li>{{ __('Du tas bort ur alla företag du är medlem i, och dina väntande inbjudningar tas bort.') }}</li>
                    <li>{{ __('Det du gjort i bokföringen finns kvar under namnet "Raderad användare".') }}</li>
                    <li>{{ __('Det går inte att ångra.') }}</li>
                </ul>

                <div class="mt-4">
                    <x-label for="delete-account-confirmation" value="{{ __('Skriv :word för att bekräfta', ['word' => $word]) }}" />
                    <x-input id="delete-account-confirmation" type="text" class="mt-1 block w-3/4" autocomplete="off"
                             wire:model="confirmation" wire:keydown.enter="deleteAccount" />
                    <x-input-error for="confirmation" class="mt-2" />
                </div>
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirming')" wire:loading.attr="disabled">{{ __('Avbryt') }}</x-secondary-button>
                <x-danger-button class="ms-3" wire:click="deleteAccount" wire:loading.attr="disabled">{{ __('Radera kontot') }}</x-danger-button>
            </x-slot>
        </x-dialog-modal>
    </x-slot>
</x-action-section>
