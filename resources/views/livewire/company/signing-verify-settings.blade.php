<x-ui.card :title="__('Kontrollsidan för signerade dokument')" :description="__('På sidan /kontrollera kan den som har en kopia av ett signerat dokument se att filen är oförändrad. Svaret visar företagets namn, hur många som har signerat och hur de kontrollerades, men aldrig namn, e-postadresser eller IP-adresser.')" data-testid="signing-verify-settings">
    <form wire:submit="save" class="space-y-3 text-sm text-gray-700">
        @unless($instanceOn)
            <p role="note" class="rounded-2xl bg-paper px-4 py-3 ring-1 ring-stone-200" data-testid="signing-verify-off">{{ __('Kontrollsidan är inte påslagen i den här installationen. Inställningen sparas, men sidan svarar inte och signeringsbevisen får ingen QR-kod förrän den slås på.') }}</p>
        @endunless

        <div class="flex items-start gap-3">
            <input id="signing-verify-enabled" type="checkbox" wire:model="enabled" class="mt-1 h-5 w-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500">
            <label for="signing-verify-enabled" class="font-medium text-gray-800">{{ __('Låt mottagare kontrollera filer på kontrollsidan') }}</label>
        </div>
        <p>{{ __('Av: kontrollsidan svarar som om filen inte fanns, och signeringsbevisen får ingen QR-kod. Gäller signeringar som skickades från och med :date, då signeringssidan började berätta om kontrollsidan. Skickar ni en fil som andra också kan ha, till exempel en standardmall, kan den som har filen se ert företagsnamn och att den har signerats.', ['date' => App\Domain\Signing\Verify\VerifyLookup::noticeDate()]) }}</p>

        <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
    </form>
</x-ui.card>
