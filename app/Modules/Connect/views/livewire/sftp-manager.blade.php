@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($statusLabels = ['received' => __('Mottagen'), 'processing' => __('Granskas'), 'analysed' => __('Väntar på bekräftelse'), 'rejected' => __('Inte mottagen')])
@php($refusalLabels = [
    'not_set_up' => __('SFTP är inte uppsatt på servern.'),
    'disabled' => __('SFTP är avstängt.'),
    'company_archived' => __('Företaget är arkiverat.'),
    'module_off' => __('NONOX API är avstängt.'),
    'responsible_lacks_rights' => __('Den ansvariga personen får inte längre läsa in SIE-filer eller hantera anslutningar. Slå på SFTP igen för att bli ansvarig själv.'),
])
<section class="space-y-3" x-data="{
    busy: false,
    error: null,
    async withPasskey(call) {
        this.error = null;
        this.busy = true;
        try {
            if (@js($skipPasskey)) {
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
    <h2 class="text-base font-semibold text-gray-900">{{ __('SFTP för SIE-filer') }}</h2>
    <p class="text-sm text-gray-600">{{ __('Ett annat program, till exempel ett kassasystem eller en redovisningsbyrås verktyg, kan lämna SIE-filer till företaget över SFTP. Inloggningen sker bara med SSH-nyckel. Varje fil granskas som när du laddar upp den själv och väntar sedan under Importera SIE tills någon har läst rapporten och bekräftat. Ingenting bokförs utan att en person har sagt ja.') }}</p>

    @if(! $available)
        <p class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">{{ __('SFTP är inte uppsatt på den här servern. Den som driftar NONOX behöver installera SFTP-servern först.') }}</p>
    @else
        @if($account && $account->is_enabled)
            <dl class="grid gap-x-6 gap-y-2 rounded-lg border border-gray-200 p-4 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-600">{{ __('Server') }}</dt><dd class="font-mono text-gray-900" data-sftp-host>{{ $host }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Port') }}</dt><dd class="font-mono text-gray-900 tabular-nums">{{ $port }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Användarnamn') }}</dt><dd class="font-mono text-gray-900" data-sftp-username>{{ $account->username }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Mappar') }}</dt><dd class="text-gray-900"><span class="font-mono">/in</span> {{ __('för filer till NONOX') }}, <span class="font-mono">/ut</span> {{ __('för filer från NONOX') }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Gränser') }}</dt><dd class="text-gray-900">{{ __('Högst :mb MB per fil, filändelse :extensions, högst :hourly SIE-filer i timmen (tillsammans med uppladdningar i webbläsaren).', ['hourly' => $hourlyLimit, 'mb' => $maxMegabytes, 'extensions' => implode(', ', array_map(fn ($extension) => '.'.$extension, $extensions))]) }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Senast') }}</dt><dd class="text-gray-900">
                    {{ __('Inloggning: :when', ['when' => $account->last_login_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i') ?? '–']) }}<br>
                    {{ __('Fil in: :when', ['when' => $account->last_upload_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i') ?? '–']) }}
                </dd></div>
                <div class="sm:col-span-2"><dt class="text-gray-600">{{ __('Ansvarig för filerna som kommer in') }}</dt><dd class="text-gray-900">{{ $account->responsible->name ?? '–' }}</dd></div>
                @if($hostFingerprints !== [])
                    <div class="sm:col-span-2"><dt class="text-gray-600">{{ __('Serverns fingeravtryck (jämför vid första anslutningen)') }}</dt><dd class="break-all font-mono text-xs text-gray-900">@foreach($hostFingerprints as $fingerprint){{ $fingerprint }}@if(! $loop->last)<br>@endif @endforeach</dd></div>
                @endif
            </dl>
            @if($refusal && $refusal !== 'disabled')
                <p class="text-sm font-medium text-red-700" role="status">{{ $refusalLabels[$refusal] ?? $refusal }}</p>
            @endif
        @elseif($account)
            <p class="text-sm font-medium text-gray-700">{{ __('SFTP är avstängt för företaget. Ingen kan logga in.') }}</p>
        @endif

        @if($canManage)
            <div class="flex flex-wrap gap-3">
                @if($account && $account->is_enabled)
                    <button type="button" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50" wire:click="disable" wire:confirm="{{ __('Stänga av SFTP? Ingen kan logga in förrän du slår på det igen. Nycklarna sparas.') }}">{{ __('Stäng av SFTP') }}</button>
                    <button type="button" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50" wire:click="putExport" wire:loading.attr="disabled">{{ __('Lägg SIE-fil för innevarande år i /ut') }}</button>
                @elseif($canImport)
                    <button type="button" class="rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700" wire:click="enable">{{ __('Slå på SFTP') }}</button>
                @else
                    <p class="text-sm text-gray-700">{{ __('Den som slår på SFTP blir ansvarig för filerna som kommer in och behöver själv få läsa in SIE-filer.') }}</p>
                @endif
            </div>
            @if($account && $account->is_enabled)
                <p class="text-xs text-gray-500">{{ __('Filer i /ut tas bort efter :days dagar.', ['days' => $exportDays]) }}</p>
            @endif
        @endif

        @if($account)
            <h3 class="text-sm font-semibold text-gray-900">{{ __('SSH-nycklar') }}</h3>
            @if($keys->isEmpty())
                <p class="text-sm text-gray-500">{{ __('Inga nycklar. Utan nyckel kan ingen logga in.') }}</p>
            @else
                <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200">
                    @foreach($keys as $key)
                        <li class="flex flex-wrap items-start justify-between gap-3 p-3 text-sm" wire:key="sftp-key-{{ $key->id }}">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900">{{ $key->name }} <span class="text-xs font-normal text-gray-500">{{ $key->algorithm === 'ssh-ed25519' ? 'ED25519' : 'RSA '.$key->bits }}</span></p>
                                <p class="break-all font-mono text-xs text-gray-700">{{ $key->fingerprint }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ __('Tillagd av :name :date', ['name' => $key->creator->name ?? '–', 'date' => $key->created_at->timezone('Europe/Stockholm')->format('Y-m-d')]) }} ·
                                    {{ __('Senast använd: :when', ['when' => $key->last_used_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i') ?? '–']) }}
                                </p>
                            </div>
                            @if($canManage)
                                <button type="button" class="text-sm font-medium text-red-700 hover:underline" wire:click="removeKey('{{ $key->id }}')" wire:confirm="{{ __('Ta bort nyckeln? Den kan inte logga in längre.') }}">{{ __('Ta bort') }}</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if($canManage)
                <form x-on:submit.prevent="withPasskey((credential) => $wire.addKey(credential))" class="space-y-4 rounded-lg border border-gray-200 p-4">
                    <h4 class="text-sm font-semibold text-gray-900">{{ __('Lägg till SSH-nyckel') }}</h4>
                    <x-ui.field for="connect_sftp_key_name" :label="__('Namn')" error="name">
                        <input id="connect_sftp_key_name" type="text" class="{{ $input }} mt-1" wire:model="keyName" maxlength="100" placeholder="{{ __('Till exempel Kassasystemet') }}">
                    </x-ui.field>
                    <x-ui.field for="connect_sftp_public_key" :label="__('Publik nyckel (ed25519, eller RSA med minst 3072 bitar)')" error="public_key">
                        <textarea id="connect_sftp_public_key" rows="3" class="{{ $input }} mt-1 font-mono text-xs" wire:model="publicKey" maxlength="4096" placeholder="ssh-ed25519 AAAA… kassa@example"></textarea>
                    </x-ui.field>
                    @if(! $hasPasskey && ! $skipPasskey)
                        <p class="text-sm text-red-700">{{ __('Du behöver en passkey för att lägga till nycklar. Lägg till en under Profil.') }}</p>
                    @else
                        <button type="submit" class="rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700" x-bind:disabled="busy">
                            <span x-show="! busy">{{ __('Lägg till med passkey') }}</span>
                            <span x-show="busy" x-cloak>{{ __('Väntar på passkeyn…') }}</span>
                        </button>
                    @endif
                </form>

                <form x-on:submit.prevent="withPasskey((credential) => $wire.saveAllowlist(credential))" class="space-y-4 rounded-lg border border-gray-200 p-4">
                    <x-ui.field for="connect_sftp_allowlist" :label="__('Tillåtna IP-adresser (valfritt, en per rad)')" error="allowlist">
                        <textarea id="connect_sftp_allowlist" rows="3" class="{{ $input }} mt-1 font-mono text-sm" wire:model="allowlist" placeholder="192.0.2.10&#10;198.51.100.0/24"></textarea>
                    </x-ui.field>
                    <p class="text-xs text-gray-500">{{ __('En tom lista släpper in alla adresser. Har det andra programmet en fast adress, skriv den här.') }}</p>
                    @if($hasPasskey || $skipPasskey)
                        <button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50" x-bind:disabled="busy">{{ __('Spara med passkey') }}</button>
                    @endif
                </form>
            @elseif($account->allowed_cidrs !== [])
                <p class="text-sm text-gray-700">{{ __('Tillåtna adresser: :list', ['list' => implode(', ', $account->allowed_cidrs)]) }}</p>
            @endif

            <h3 class="text-sm font-semibold text-gray-900">{{ __('Filer som har kommit in') }}</h3>
            @if($uploads->isEmpty())
                <p class="text-sm text-gray-500">{{ __('Inga filer ännu.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <caption class="sr-only">{{ __('Filer som har kommit in via SFTP') }}</caption>
                        <thead class="text-left text-gray-600">
                            <tr>
                                <th class="px-2 py-1 font-medium">{{ __('Tid') }}</th>
                                <th class="px-2 py-1 font-medium">{{ __('Fil') }}</th>
                                <th class="px-2 py-1 font-medium text-right">{{ __('Storlek') }}</th>
                                <th class="px-2 py-1 font-medium">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($uploads as $upload)
                                <tr wire:key="sftp-upload-{{ $upload->id }}">
                                    <td class="px-2 py-1 text-gray-700 tabular-nums">{{ $upload->created_at->timezone('Europe/Stockholm')->format('Y-m-d H:i') }}</td>
                                    <td class="px-2 py-1 break-all font-mono text-gray-700">{{ $upload->file_name }}</td>
                                    <td class="px-2 py-1 text-right text-gray-700 tabular-nums">{{ number_format($upload->size / 1024, 0, ',', ' ') }} kB</td>
                                    <td class="px-2 py-1 {{ $upload->status === 'rejected' ? 'text-red-700' : 'text-gray-700' }}">
                                        @if($upload->status === 'analysed' && $upload->sie_import_id && $canImport)
                                            <a class="font-medium text-accent-700 hover:underline" href="{{ route('accounting.sieImports.show', $upload->sie_import_id) }}">{{ __('Läs rapporten') }}</a>
                                        @else
                                            {{ $statusLabels[$upload->status] ?? $upload->status }}
                                        @endif
                                        @if($upload->reason)<span class="block text-xs">{{ $upload->reason }}</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    @endif
    @if($available)
        <p class="text-xs text-gray-500">{{ __('SFTP-servern är SFTPGo av Nicola Murino och bidragsgivare, fri programvara under AGPL-3.0. NONOX är inte knutet till eller godkänt av SFTPGo-projektet.') }} <a class="underline" href="{{ $sftpgoSource }}" rel="noopener" target="_blank">{{ __('Källkoden för SFTPGo') }}</a></p>
    @endif
    <p class="text-sm text-red-700" role="alert" x-show="error" x-text="error" x-cloak></p>
    @error('passkey') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
    @error('sftp') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
    @error('export') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
</section>
