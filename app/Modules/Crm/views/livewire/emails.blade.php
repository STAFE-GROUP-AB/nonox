@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $zone = 'Europe/Stockholm';
@endphp

<div class="space-y-6">
    @if($shown)
        <x-ui.card :title="$shown->subject" :description="$shown->direction->label().' · '.$shown->sent_at->setTimezone($zone)->translatedFormat('j M Y H:i')">
            <x-slot:action>
                <button type="button" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="$set('show', '')">{{ __('Stäng') }}</button>
            </x-slot:action>
            @if($shown->status === App\Modules\Crm\Domain\EmailStatus::Restricted)
                <p class="mt-4 text-sm text-gray-700">{{ __('Behandlingen av uppgifterna är begränsad. Mejlet är bara sparat och visas inte.') }}</p>
            @else
                <dl class="mt-4 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                    <dt class="font-medium text-gray-600">{{ __('Från') }}</dt><dd class="break-all">{{ $shown->participants['from_name'] ?? '' }} {{ $shown->participants['from'] }}</dd>
                    <dt class="font-medium text-gray-600">{{ __('Till') }}</dt><dd class="break-all">{{ implode(', ', $shown->participants['to']) ?: '–' }}</dd>
                    @if($shown->participants['cc'] !== [])<dt class="font-medium text-gray-600">{{ __('Kopia') }}</dt><dd class="break-all">{{ implode(', ', $shown->participants['cc']) }}</dd>@endif
                    <dt class="font-medium text-gray-600">{{ __('Loggat av') }}</dt><dd>{{ $shown->forwarder?->name ?? '–' }}</dd>
                    @if($shownAccounts->isNotEmpty())
                        <dt class="font-medium text-gray-600">{{ __('Kunder') }}</dt>
                        <dd>@foreach($shownAccounts as $account)<a href="{{ route('crm.accounts.show', $account) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $account->name }}</a>@if(! $loop->last), @endif @endforeach</dd>
                    @endif
                    @if($shown->attachment_names)
                        <dt class="font-medium text-gray-600">{{ __('Bilagor (sparas inte)') }}</dt><dd class="break-all">{{ implode(', ', $shown->attachment_names) }}</dd>
                    @endif
                </dl>
                <div class="mt-4 whitespace-pre-line rounded-2xl bg-stone-50 p-4 text-sm leading-relaxed text-gray-900 ring-1 ring-stone-200" data-testid="crm-email-body">{{ $shown->body ?? __('Mejlet har ingen text.') }}</div>
            @endif
        </x-ui.card>
    @endif

    @if($canManage)
        <x-ui.card :title="__('Logga mejl i CRM')" :description="__('Skicka en kopia (hemlig kopia) eller vidarebefordra ett mejl till adressen nedan, från adressen du loggar in i NONOX med. Mejlet hamnar på rätt kund när avsändaren eller mottagaren finns som person eller kund.')">
            <p class="mt-4 flex flex-wrap items-center gap-3">
                <code class="rounded-lg bg-stone-100 px-3 py-2 text-sm font-semibold text-ink break-all" data-testid="crm-mail-address">{{ $address }}</code>
            </p>
            <p class="mt-3 text-sm text-gray-600">
                @if($viaImap)
                    {{ __('Adressen är inkorgens egen adress med +crm. Er e-postserver levererar den till samma brevlåda, där NONOX hämtar den var femte minut.') }}
                @else
                    {{ __('Adressen är inkorgens adress med +crm. Byter ägaren inkorgens adress byter också den här.') }}
                @endif
                {{ __('Bara text sparas, krypterad. Bilagor sparas inte.') }}
                <a href="{{ route('manual.article', ['crm', 'logga-mejl']) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Om att logga mejl') }}</a>
            </p>
            <p class="mt-3 text-sm"><a href="{{ route('crm.templates') }}" class="inline-flex min-h-11 items-center font-medium text-accent-600 hover:text-accent-700 sm:min-h-0">{{ __('Mejlmallar') }}</a></p>
        </x-ui.card>

        @if($panel === 'link' && $emailId)
            <x-ui.card :title="__('Koppla mejlet till en kund')">
                <form wire:submit="link" class="mt-4 grid gap-4 sm:grid-cols-2">
                    <x-ui.field for="email_account_search" :label="__('Kund')" error="accountId" class="sm:col-span-2">
                        <input id="email_account_search" type="search" class="{{ $input }} mt-1" wire:model.live.debounce.300ms="accountSearch" placeholder="{{ __('Sök på namn') }}" autocomplete="off">
                    </x-ui.field>
                    @if($accounts->isNotEmpty() && ! $chosen)
                        <ul class="sm:col-span-2 divide-y divide-gray-100 rounded-xl ring-1 ring-stone-200">
                            @foreach($accounts as $account)
                                <li wire:key="pick-{{ $account->id }}"><button type="button" class="block w-full min-h-11 px-4 text-left text-sm font-medium hover:bg-stone-50" wire:click="chooseAccount({{ $account->id }})">{{ $account->name }}</button></li>
                            @endforeach
                        </ul>
                    @endif
                    @if($chosen)
                        <p class="sm:col-span-2 text-sm">{{ __('Vald kund:') }} <span class="font-semibold">{{ $chosen->name }}</span> <button type="button" class="ms-2 text-gray-600 underline" wire:click="$set('accountId', '')">{{ __('Byt') }}</button></p>
                        <x-ui.field for="email_person" :label="__('Person (frivilligt)')" error="personId">
                            <select id="email_person" class="{{ $input }} mt-1" wire:model="personId">
                                <option value="">{{ __('Ingen') }}</option>
                                @foreach($chosen->people as $person)
                                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif
                    <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                        <x-button wire:loading.attr="disabled">{{ __('Logga mejlet') }}</x-button>
                        <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                    </div>
                </form>
            </x-ui.card>
        @elseif($panel === 'prospect' && $emailId)
            <x-ui.card :title="__('Skapa prospekt av mejlet')" :description="__('Ett nytt prospekt och en kontaktperson med en av adresserna i mejlet. Ange den rättsliga grunden för att spara personen.')">
                <form wire:submit="createProspect" class="mt-4 grid gap-4 sm:grid-cols-2">
                    <x-ui.field for="prospect_address" :label="__('E-postadress')" error="prospect.address">
                        <select id="prospect_address" class="{{ $input }} mt-1" wire:model="prospect.address">
                            @foreach(($current?->otherParty() ?? []) as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="prospect_kind" :label="__('Typ')" error="prospect.kind">
                        <select id="prospect_kind" class="{{ $input }} mt-1" wire:model="prospect.kind">
                            @foreach($kinds as $kind)
                                <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="prospect_account" :label="__('Företagets namn')" error="prospect.account_name">
                        <input id="prospect_account" type="text" class="{{ $input }} mt-1" wire:model="prospect.account_name">
                    </x-ui.field>
                    <x-ui.field for="prospect_person" :label="__('Personens namn')" error="prospect.person_name">
                        <input id="prospect_person" type="text" class="{{ $input }} mt-1" wire:model="prospect.person_name">
                    </x-ui.field>
                    <x-ui.field for="prospect_basis" :label="__('Rättslig grund')" error="prospect.legal_basis">
                        <select id="prospect_basis" class="{{ $input }} mt-1" wire:model.live="prospect.legal_basis">
                            @foreach($bases as $basis)
                                <option value="{{ $basis->value }}">{{ $basis->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    @if($prospect['legal_basis'] === 'CONSENT')
                        <x-ui.field for="prospect_consent" :label="__('Samtycke givet')" error="prospect.consent_at">
                            <input id="prospect_consent" type="date" class="{{ $input }} mt-1" wire:model="prospect.consent_at">
                        </x-ui.field>
                    @endif
                    <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                        <x-button wire:loading.attr="disabled">{{ __('Skapa prospekt och logga') }}</x-button>
                        <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        <x-ui.card :title="__('Ej kopplade')" :description="__('Mejl där ingen avsändare eller mottagare finns i CRM, och mejl ni har skickat till någon som är märkt Kontakta inte. Koppla, skapa ett prospekt eller ta bort. Det som inte hanteras tas bort efter 30 dagar.')">
            @if($waiting->isEmpty())
                <x-ui.empty-state class="mt-6" :title="__('Inga mejl väntar')">{{ __('Allt som har loggats har hamnat på en kund.') }}</x-ui.empty-state>
            @else
                <ul class="mt-4 divide-y divide-gray-100" data-testid="crm-waiting-emails">
                    @foreach($waiting as $email)
                        <li wire:key="email-{{ $email->id }}" class="flex flex-wrap items-start gap-3 py-3">
                            <span class="min-w-0 flex-1 basis-60 text-sm">
                                <span class="block font-medium text-gray-900">{{ $email->subject }}@if($email->status === App\Modules\Crm\Domain\EmailStatus::Held) <x-ui.badge tone="accent" class="ms-1">{{ __('Kontakta inte') }}</x-ui.badge>@endif</span>
                                <span class="block text-gray-600">{{ $email->direction->label() }} · {{ $email->sent_at->setTimezone($zone)->translatedFormat('j M Y H:i') }} · {{ __('loggat av :name', ['name' => $email->forwarder?->name ?? '–']) }}</span>
                                <span class="block break-all text-gray-600">{{ implode(', ', $email->otherParty()) ?: __('Inga andra adresser än era egna') }}</span>
                                @if($email->excerpt)<span class="mt-1 block text-gray-800">{{ $email->excerpt }}</span>@endif
                                @if($email->status === App\Modules\Crm\Domain\EmailStatus::Held)<span class="mt-1 block text-accent-800">{{ __('Personen har bett att inte bli kontaktad. Mejlet kan loggas som en anteckning, inte som ett mejl.') }}</span>@endif
                            </span>
                            <span class="flex flex-wrap gap-x-4 text-sm">
                                <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700" wire:click="startLink({{ $email->id }})">{{ __('Koppla') }}</button>
                                @if($email->status === App\Modules\Crm\Domain\EmailStatus::Unmatched && $email->otherParty() !== [])
                                    <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700" wire:click="startProspect({{ $email->id }})">{{ __('Skapa prospekt') }}</button>
                                @endif
                                <button type="button" class="min-h-11 text-gray-700 underline" wire:click="dismiss({{ $email->id }})" wire:confirm="{{ __('Ta bort mejlet? Inget av det sparas.') }}">{{ __('Ta bort') }}</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4">{{ $waiting->links() }}</div>
            @endif
        </x-ui.card>
    @endif
</div>
