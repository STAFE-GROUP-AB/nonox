@php($label = fn (string $group, ?string $key): string => App\Domain\Waitlist\WaitlistOptions::label($group, $key))
<div class="space-y-6" x-data="{
    busy: false,
    error: null,
    confirmDelete: false,
    async withPasskey(call) {
        this.error = null;
        this.busy = true;
        try {
            if (@js($fresh)) {
                await call(null);
                return;
            }
            if (! window.NonoxPasskeys || ! window.NonoxPasskeys.supported()) {
                this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(null) : @js(__('Sidan kunde inte ladda stödet för passkeys. Ladda om sidan och försök igen.'));
                return;
            }
            const options = await $wire.passkeyOptions();
            if (! options) { return; }
            await call(await window.NonoxPasskeys.authenticate(options));
        } catch (error) {
            this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(error) : String(error);
        } finally {
            this.busy = false;
        }
    },
}">
    <x-ui.page-heading :title="$entry->company_name" :lead="__('Anmäld :date', ['date' => $entry->created_at->format('Y-m-d H:i')])">
        <x-slot:action>
            <x-ui.badge :tone="$entry->status === App\Domain\Waitlist\WaitlistStatus::Approved ? 'success' : ($entry->status === App\Domain\Waitlist\WaitlistStatus::Pending ? 'accent' : 'neutral')">{{ $entry->status->label() }}</x-ui.badge>
        </x-slot:action>
    </x-ui.page-heading>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card :title="__('Svaren')" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 text-sm">
                <div><dt class="font-medium text-gray-500">{{ __('Namn') }}</dt><dd class="mt-1 text-ink">{{ $entry->name }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('E-post') }}</dt><dd class="mt-1 text-ink break-all">{{ $entry->email }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Telefon') }}</dt><dd class="mt-1 text-ink">{{ $entry->phone ?? '–' }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Organisationsnummer') }}</dt><dd class="mt-1 text-ink tabular-nums">{{ $entry->org_number ?? '–' }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Bransch') }}</dt><dd class="mt-1 text-ink">{{ $label('industry', $entry->industry) }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Anställda') }}</dt><dd class="mt-1 text-ink">{{ $label('employees', $entry->employees) }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Nuvarande system') }}</dt><dd class="mt-1 text-ink">{{ $label('current_system', $entry->current_system) }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Samtycke') }}</dt><dd class="mt-1 text-ink tabular-nums">{{ $entry->consent_at->format('Y-m-d H:i') }}</dd></div>
                <div class="sm:col-span-2"><dt class="font-medium text-gray-500">{{ __('Vad vill du testa i NONOX?') }}</dt><dd class="mt-1 whitespace-pre-line text-ink">{{ $entry->wants_to_test ?? '–' }}</dd></div>
                <div class="sm:col-span-2"><dt class="font-medium text-gray-500">{{ __('Varför vill du byta system?') }}</dt><dd class="mt-1 whitespace-pre-line text-ink">{{ $entry->why_switch ?? '–' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('Beslut')">
            @if($entry->status->isOpen())
                <div class="space-y-4">
                    <div>
                        <label for="hq-note" class="block text-sm font-medium text-gray-700">{{ __('Anteckning (valfri, syns bara i HQ)') }}</label>
                        <textarea id="hq-note" wire:model="note" rows="3" maxlength="2000" class="mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-accent-500 focus:ring-accent-500"></textarea>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="rounded-xl bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700 disabled:opacity-60" x-bind:disabled="busy" x-on:click="withPasskey((credential) => $wire.approve(credential))">{{ __('Godkänn') }}</button>
                        <button type="button" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-300 hover:ring-stone-400 disabled:opacity-60" x-bind:disabled="busy" x-on:click="withPasskey((credential) => $wire.park(credential))">{{ __('Parkera') }}</button>
                        <button type="button" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-red-700 ring-1 ring-red-200 hover:ring-red-300 disabled:opacity-60" x-bind:disabled="busy" x-on:click="withPasskey((credential) => $wire.reject(credential))">{{ __('Neka') }}</button>
                    </div>
                    <p class="text-sm text-gray-600">{{ __('Godkänn skapar ett konto för e-postadressen och mejlar att personen kan logga in. Neka och Parkera mejlar ingen.') }}</p>
                </div>
            @else
                <dl class="space-y-3 text-sm">
                    <div><dt class="font-medium text-gray-500">{{ __('Beslut') }}</dt><dd class="mt-1 text-ink">{{ $entry->status->label() }}@if($entry->decided_at) · <span class="tabular-nums">{{ $entry->decided_at->format('Y-m-d H:i') }}</span>@endif</dd></div>
                    @if($entry->decider)<div><dt class="font-medium text-gray-500">{{ __('Beslutat av') }}</dt><dd class="mt-1 text-ink">{{ $entry->decider->name }}</dd></div>@endif
                    @if($entry->decision_note)<div><dt class="font-medium text-gray-500">{{ __('Anteckning') }}</dt><dd class="mt-1 whitespace-pre-line text-ink">{{ $entry->decision_note }}</dd></div>@endif
                    @if($entry->approvedUser)<div><dt class="font-medium text-gray-500">{{ __('Konto') }}</dt><dd class="mt-1 text-ink">{{ $entry->approvedUser->email_verified_at ? __('Har loggat in') : __('Har inte loggat in än') }}</dd></div>@endif
                </dl>
            @endif

            @if($entry->status->isOpen() && $entry->decision_note)
                <p class="mt-4 text-sm text-gray-600"><span class="font-medium">{{ __('Tidigare anteckning:') }}</span> {{ $entry->decision_note }}</p>
            @endif

            <div class="mt-6 border-t border-stone-200 pt-4">
                <button type="button" class="text-sm font-semibold text-red-700 hover:underline" x-show="! confirmDelete" x-on:click="confirmDelete = true">{{ __('Radera anmälan') }}</button>
                <div x-show="confirmDelete" x-cloak class="space-y-3">
                    <p class="text-sm text-gray-700">{{ __('Anmälan och alla svar tas bort för gott. Personen i driftbolagets CRM raderas också och spärras så att den inte läggs till igen; företagets konto där raderas om ingen har arbetat med det. Ett konto för inloggning som redan har skapats påverkas inte. Gör det här när personen ber om det.') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="rounded-xl bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-60" x-bind:disabled="busy" x-on:click="withPasskey((credential) => $wire.delete(credential))">{{ __('Ja, radera') }}</button>
                        <button type="button" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-200" x-on:click="confirmDelete = false">{{ __('Avbryt') }}</button>
                    </div>
                </div>
            </div>

            @if(! $hasPasskey && ! $fresh)
                <p class="mt-4 text-sm text-red-700">{{ __('Du behöver en passkey för att fatta beslut i HQ. Lägg till en under Profil.') }}</p>
            @endif
            <p class="mt-4 text-sm text-red-700" role="alert" x-show="error" x-text="error" x-cloak></p>
            @error('passkey') <p class="mt-4 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
            @error('entry') <p class="mt-4 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
        </x-ui.card>
    </div>
</div>
