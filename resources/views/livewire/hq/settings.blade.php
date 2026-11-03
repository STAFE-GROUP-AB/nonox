@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-6" x-data="{
    busy: false,
    error: null,
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
    <form class="grid gap-6 lg:grid-cols-2" x-on:submit.prevent="withPasskey((credential) => $wire.save(credential))" novalidate>
        <x-ui.card :title="__('Driftbolag')">
            <div class="space-y-4">
                <p class="text-sm text-gray-600">{{ __('Företaget i installationen som driver tjänsten. Alla andra företag blir kunder i dess kundregister, och anmälningarna i kön blir prospekt i dess CRM. Tomt betyder att funktionen är avstängd.') }}</p>
                <div>
                    <label for="hq-operator" class="block text-sm font-medium text-gray-700">{{ __('Driftbolag') }}</label>
                    <select id="hq-operator" wire:model.live="operatorCompanyId" class="mt-1 {{ $input }}" @error('operator_company_id') aria-invalid="true" aria-describedby="hq-operator-error" @enderror>
                        <option value="">{{ __('Inget (avstängt)') }}</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }} ({{ $company->id }})</option>
                        @endforeach
                    </select>
                    @error('operator_company_id') <p id="hq-operator-error" class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="hq-customers-target" class="block text-sm font-medium text-gray-700">{{ __('Kunderna hamnar i') }}</label>
                    <select id="hq-customers-target" wire:model="customersCompanyId" class="mt-1 {{ $input }}" @disabled($operatorCompanyId === '') aria-describedby="hq-customers-target-hint" @error('customers_company_id') aria-invalid="true" @enderror>
                        <option value="">{{ __('Driftbolaget') }}</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }} ({{ $company->id }})</option>
                        @endforeach
                    </select>
                    <p id="hq-customers-target-hint" class="mt-1 text-sm text-gray-600">{{ __('Varje företag i NONOX blir kund här, utom driftbolaget, de valda företagen själva, testföretag och arkiverade företag.') }}</p>
                    @error('customers_company_id') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="hq-waitlist-target" class="block text-sm font-medium text-gray-700">{{ __('Köanmälningarna hamnar i') }}</label>
                    <select id="hq-waitlist-target" wire:model="waitlistCompanyId" class="mt-1 {{ $input }}" @disabled($operatorCompanyId === '') aria-describedby="hq-waitlist-target-hint" @error('waitlist_company_id') aria-invalid="true" @enderror>
                        <option value="">{{ __('Driftbolaget') }}</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }} ({{ $company->id }})</option>
                        @endforeach
                    </select>
                    <p id="hq-waitlist-target-hint" class="mt-1 text-sm text-gray-600">{{ __('Varje anmälan blir ett prospekt med den sökande som kontaktperson (samtycke). Kräver att CRM är påslaget i företaget.') }}</p>
                    @error('waitlist_company_id') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Vägar in')">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <input id="hq-waitlist-open" type="checkbox" wire:model="waitlistOpen" class="mt-1 h-5 w-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" aria-describedby="hq-waitlist-open-hint">
                    <div>
                        <label for="hq-waitlist-open" class="text-sm font-medium text-gray-800">{{ __('Kön är öppen') }}</label>
                        <p id="hq-waitlist-open-hint" class="text-sm text-gray-600">{{ __('Stängd kö: /ko visar att kön är stängd och tar inte emot anmälningar. De som redan står i kö ligger kvar.') }}</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <input id="hq-registration-open" type="checkbox" wire:model="registrationOpen" class="mt-1 h-5 w-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" aria-describedby="hq-registration-open-hint">
                    <div>
                        <label for="hq-registration-open" class="text-sm font-medium text-gray-800">{{ __('Självregistrering är öppen') }}</label>
                        <p id="hq-registration-open-hint" class="text-sm text-gray-600">{{ __('Öppen: vem som helst kan skapa ett konto på inloggningssidan med en kod till sin e-post och sedan lägga upp ett företag. Stängd: bara godkända i kön och inbjudna får ett konto.') }}</p>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <div class="lg:col-span-2 flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded-xl bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700 disabled:opacity-60" x-bind:disabled="busy">{{ __('Spara inställningarna') }}</button>
            <p class="text-sm text-gray-600">{{ __('Ändringarna bekräftas med din passkey och skrivs i behandlingshistoriken.') }}</p>
        </div>
    </form>

    @if(! $hasPasskey && ! $fresh)
        <p class="text-sm text-red-700">{{ __('Du behöver en passkey för att ändra inställningarna i HQ. Lägg till en under Profil.') }}</p>
    @endif
    <p class="text-sm text-red-700" role="alert" x-show="error" x-text="error" x-cloak></p>
    @error('passkey') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror

    <x-ui.card :title="__('Synk till driftbolaget')">
        @if($customersTarget === null)
            <p class="text-sm text-gray-600">{{ __('Avstängt. Välj ett driftbolag för att företagen och köanmälningarna ska föras över.') }}</p>
        @else
            @if($waitlistCrmOff)
                <p class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200" role="status" data-crm-off>{{ __('CRM är inte påslaget i företaget som köanmälningarna ska till. Anmälningarna förs inte över förrän CRM slås på där; kör sedan synken av befintliga uppgifter.') }}</p>
            @endif
            <dl class="grid gap-4 sm:grid-cols-4 text-sm">
                <div><dt class="font-medium text-gray-500">{{ __('Företag som är kunder') }}</dt><dd class="mt-1 text-xl font-semibold tabular-nums text-ink">{{ $customerLinks }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Prospekt från kön') }}</dt><dd class="mt-1 text-xl font-semibold tabular-nums text-ink">{{ $prospectLinks }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Spärrade (kontaktas inte)') }}</dt><dd class="mt-1 text-xl font-semibold tabular-nums text-ink">{{ $suppressed }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Synk misslyckades') }}</dt><dd class="mt-1 text-xl font-semibold tabular-nums {{ $failureCount > 0 ? 'text-red-700' : 'text-ink' }}">{{ $failureCount }}</dd></div>
            </dl>
            <p class="mt-4 text-sm text-gray-600">{{ __('Nya företag och anmälningar förs över av sig själva. Det som fanns innan driftbolaget valdes förs över med kommandot hq:sync-operator-customers (prova först med --dry-run).') }}</p>
        @endif

        @if($failureCount > 0)
            <div class="mt-6 border-t border-stone-200 pt-4">
                <h3 class="text-sm font-semibold text-ink">{{ __('Synk misslyckades') }}</h3>
                <div class="mt-2 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Källa') }}</th>
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Fel') }}</th>
                                <th scope="col" class="py-2 font-medium">{{ __('Senast') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($failures as $failure)
                                <tr>
                                    <td class="py-2 pr-4">
                                        @if($failure->source_company_id)
                                            <a href="{{ route('hq.customers.company', $failure->source_company_id) }}" class="font-medium text-accent-700 hover:underline">{{ __('Företag :id', ['id' => $failure->source_company_id]) }}</a>
                                        @elseif($failure->waitlist_entry_id)
                                            <a href="{{ route('hq.waitlist.show', $failure->waitlist_entry_id) }}" class="font-medium text-accent-700 hover:underline">{{ __('Anmälan :id', ['id' => $failure->waitlist_entry_id]) }}</a>
                                        @else
                                            –
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-gray-700">{{ $failure->error }}</td>
                                    <td class="py-2 tabular-nums text-gray-700">{{ $failure->failed_at?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button type="button" class="mt-4 rounded-xl bg-white px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-300 hover:ring-stone-400 disabled:opacity-60" x-bind:disabled="busy" x-on:click="withPasskey((credential) => $wire.retryFailed(credential))">{{ __('Försök igen') }}</button>
            </div>
        @endif
    </x-ui.card>
</div>
