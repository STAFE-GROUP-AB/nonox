@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm')
<div class="space-y-8">
    <section class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-900">{{ __('Företagets adress') }}</h3>
        @php($address = App\Modules\Inbox\Support\InboxAddress::forSlug($mailbox->slug))
        <div class="flex flex-wrap items-center gap-2" x-data="{ copied: false }">
            <code class="rounded-lg bg-gray-50 px-3 py-2 font-mono text-sm text-gray-900" data-address>{{ $address }}</code>
            <button type="button" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                x-on:click="navigator.clipboard.writeText(@js($address)); copied = true; setTimeout(() => copied = false, 2000)">
                <span x-show="! copied">{{ __('Kopiera') }}</span><span x-show="copied" x-cloak>{{ __('Kopierad') }}</span>
            </button>
        </div>
        <p class="text-sm text-gray-600">{{ __('Med ett tillägg efter plustecknet berättar avsändaren vad som skickas:') }}</p>
        <ul class="list-disc space-y-1 pl-5 font-mono text-sm text-gray-700">
            @foreach($aliases as $alias)<li>{{ App\Modules\Inbox\Support\InboxAddress::forSlug($mailbox->slug, $alias) }}</li>@endforeach
        </ul>
        <p class="text-sm text-gray-600">{{ __('Det finns också en gemensam adress, :shared, för det du vidarebefordrar från din egen e-post. Den tar bara emot post från adressen du loggar in med, och den måste stå på listan nedanför. Leverantörer och kassasystem skickar till företagets egen adress ovanför.', ['shared' => $shared]) }}</p>
        @if($isOwner)
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" wire:click="regenerate" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $confirmRegenerate ? __('Ja, byt adress') : __('Byt till en ny adress') }}
                </button>
                @if($confirmRegenerate)
                    <span class="text-sm text-red-700">{{ __('Den gamla adressen slutar ta emot e-post direkt. Alla som skickar dit måste få den nya.') }}</span>
                @endif
            </div>
        @endif
    </section>

    <section class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-900">{{ __('Godkända avsändare') }}</h3>
        <p class="text-sm text-gray-600">{{ __('Bara e-post från adresser och domäner på listan tolkas. Annat hamnar i karantän tills någon godkänner avsändaren.') }}</p>
        <form wire:submit="addSender" class="flex flex-wrap items-start gap-2">
            <x-ui.field for="inbox_pattern" :label="__('Adress eller domän')" error="pattern" class="min-w-64 flex-1">
                <input id="inbox_pattern" type="text" class="{{ $input }} mt-1" wire:model="newPattern" placeholder="kassa@kassasystem.se {{ __('eller') }} @kassasystem.se">
            </x-ui.field>
            <x-ui.field for="inbox_note" :label="__('Anteckning')" class="min-w-48 flex-1">
                <input id="inbox_note" type="text" class="{{ $input }} mt-1" wire:model="newNote" maxlength="255">
            </x-ui.field>
            <button type="submit" class="mt-6 rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Lägg till') }}</button>
        </form>
        @if($senders->isEmpty())
            <p class="text-sm text-gray-500">{{ __('Listan är tom. Ingen kan skicka något som tolkas ännu.') }}</p>
        @else
            <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200">
                @foreach($senders as $sender)
                    <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm" wire:key="sender-{{ $sender->id }}">
                        <span><span class="font-mono text-gray-900">{{ $sender->pattern }}</span> @if($sender->note)<span class="text-gray-500">– {{ $sender->note }}</span>@endif</span>
                        <button type="button" wire:click="removeSender({{ $sender->id }})" class="text-sm font-medium text-red-700 hover:underline">{{ __('Ta bort') }}</button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <form wire:submit="saveOptions" class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-900">{{ __('Tolkning och aviseringar') }}</h3>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="aiInterpretation" class="mt-0.5 rounded border-gray-300 text-accent-600 focus:ring-accent-500">
            <span>{{ __('Låt NONOX AI läsa underlag som ingen mall känner igen (kräver att modulen NONOX AI är påslagen; underlaget skickas då till er AI-leverantör)') }}</span></label>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="notifyOnAction" class="mt-0.5 rounded border-gray-300 text-accent-600 focus:ring-accent-500">
            <span>{{ __('Skicka e-post när något behöver granskas (högst en gång i timmen)') }}</span></label>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="dailySummary" class="mt-0.5 rounded border-gray-300 text-accent-600 focus:ring-accent-500">
            <span>{{ __('Skicka en daglig sammanfattning på morgonen') }}</span></label>
        <button type="submit" class="rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Spara') }}</button>
    </form>

    <section class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-900">{{ __('Sparade mallar') }}</h3>
        <p class="text-sm text-gray-600">{{ __('När du godkänner ett underlag sparas hur det tolkades. Nästa underlag från samma avsändare i samma format tolkas med mallen, utan AI.') }}</p>
        @if($templates->isEmpty())
            <p class="text-sm text-gray-500">{{ __('Inga mallar ännu.') }}</p>
        @else
            <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200">
                @foreach($templates as $template)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-3 py-2 text-sm" wire:key="template-{{ $template->id }}">
                        <span>
                            <span class="font-medium text-gray-900">{{ $template->name }}</span>
                            <span class="block text-xs text-gray-500">{{ __('Använd :count gånger', ['count' => $template->uses]) }} · {{ strtoupper($template->format) }}
                                @if($template->auto_book) · {{ __('bokförs automatiskt i :name namn', ['name' => $template->autoBookUser?->name ?? '?']) }}@endif</span>
                        </span>
                        <span class="flex gap-3">
                            <button type="button" wire:click="toggleAutoBook({{ $template->id }})" class="font-medium text-accent-700 hover:underline">
                                {{ $template->auto_book ? __('Stäng av automatisk bokföring') : __('Bokför automatiskt') }}
                            </button>
                            <button type="button" wire:click="deleteTemplate({{ $template->id }})" class="font-medium text-red-700 hover:underline">{{ __('Ta bort') }}</button>
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="text-xs text-gray-500">{{ __('Automatisk bokföring sker bara när underlaget matchar mallen exakt, verifikationen balanserar, avsändaren är godkänd och inga varningar finns. AI bokför aldrig själv.') }}</p>
        @endif
    </section>

    <form wire:submit="saveImap" class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-900">{{ __('Egen brevlåda (IMAP)') }}</h3>
        <p class="text-sm text-gray-600">{{ __('Kör ni NONOX själva kan NONOX hämta e-post från en egen brevlåda i stället. Använd en brevlåda som bara används för underlag.') }}</p>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="imapEnabled" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500"> {{ __('Hämta e-post från en egen brevlåda') }}</label>
        @if($imapEnabled)
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field for="imap_host" :label="__('Server')" error="imap_host"><input id="imap_host" type="text" class="{{ $input }} mt-1" wire:model="imapHost" placeholder="imap.exempel.se"></x-ui.field>
                <x-ui.field for="imap_port" :label="__('Port')" error="imap_port"><input id="imap_port" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="imapPort"></x-ui.field>
                <x-ui.field for="imap_encryption" :label="__('Kryptering')" error="imap_encryption">
                    <select id="imap_encryption" class="{{ $input }} mt-1" wire:model="imapEncryption">
                        <option value="ssl">SSL/TLS</option><option value="tls">STARTTLS</option><option value="none">{{ __('Ingen (rekommenderas inte)') }}</option>
                    </select>
                </x-ui.field>
                <x-ui.field for="imap_folder" :label="__('Mapp')" error="imap_folder"><input id="imap_folder" type="text" class="{{ $input }} mt-1" wire:model="imapFolder"></x-ui.field>
                <x-ui.field for="imap_username" :label="__('Användarnamn')" error="imap_username"><input id="imap_username" type="text" autocomplete="off" class="{{ $input }} mt-1" wire:model="imapUsername"></x-ui.field>
                <x-ui.field for="imap_password" :label="__('Lösenord')" error="imap_password" :hint="$hasPassword ? __('Ett lösenord är sparat. Fyll bara i fältet för att byta det.') : __('Lösenordet sparas krypterat och visas aldrig igen.')">
                    <input id="imap_password" type="password" autocomplete="new-password" class="{{ $input }} mt-1" wire:model="imapPassword">
                </x-ui.field>
            </div>
            @if($mailbox->imap_last_error)
                <p class="text-sm text-red-700">{{ __('Senaste hämtningen misslyckades: :error', ['error' => $mailbox->imap_last_error]) }}</p>
            @elseif($mailbox->imap_last_fetched_at)
                <p class="text-sm text-gray-600">{{ __('Senast hämtat :time.', ['time' => $mailbox->imap_last_fetched_at->timezone('Europe/Stockholm')->format('Y-m-d H:i')]) }}</p>
            @endif
        @endif
        <button type="submit" class="rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Spara brevlådan') }}</button>
    </form>
</div>
