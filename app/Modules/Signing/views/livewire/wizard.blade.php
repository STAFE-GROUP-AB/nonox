@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $zone = config('app.timezone');
    $verificationLabel = fn (string $value): string => App\Domain\Signing\Verification\SignerCheck::from($value)->label();
@endphp

<div class="space-y-6" data-testid="signing-wizard">
    <ol class="flex flex-wrap gap-2 text-sm" aria-label="{{ __('Stegen') }}" data-testid="signing-wizard-steps">
        @foreach($steps as $number => $label)
            <li @class(['inline-flex min-h-11 items-center rounded-xl px-4 font-medium sm:min-h-0 sm:py-2', 'bg-ink text-white' => $step === $number, 'bg-white text-ink ring-1 ring-stone-200' => $step !== $number]) @if($step === $number) aria-current="step" @endif>{{ $number }}. {{ __($label) }}</li>
        @endforeach
    </ol>

    @if($document !== null && $step > 1)
        <p class="text-sm text-gray-700" data-testid="signing-wizard-document-line">{{ __('Dokument: :title', ['title' => $document->signingTitle()]) }}@if($pages) · {{ trans_choice('{1} :count sida|[2,*] :count sidor', $pages, ['count' => $pages]) }}@endif</p>
    @endif

    @if($step === 1)
        <x-ui.card :title="__('Dokument')" :description="$document === null || $document instanceof App\Modules\Signing\Models\SigningDocument ? __('Ladda upp en PDF på högst 20 MB och 100 sidor, eller välj ett dokument du redan har laddat upp. Filen ändras aldrig: den som undertecknarna ser är exakt den du laddar upp.') : __('Dokumentet skickas som det är. Offerten eller avtalet kan inte ändras medan det är ute för signering.')">
            @if($document !== null)
                <p class="mt-4 text-sm text-gray-900" data-testid="signing-wizard-document">{{ $document->signingTitle() }}@if($pages) · {{ trans_choice('{1} :count sida|[2,*] :count sidor', $pages, ['count' => $pages]) }}@endif</p>
                <div class="mt-4 flex flex-wrap items-center gap-4">
                    <x-button type="button" wire:click="next">{{ __('Nästa') }}</x-button>
                    <a href="{{ route('signing.new') }}" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0">{{ __('Välj ett annat dokument') }}</a>
                </div>
            @else
                <form method="POST" action="{{ route('signing.upload') }}" enctype="multipart/form-data" class="mt-4 grid gap-4 sm:grid-cols-2" data-testid="signing-upload-form">
                    @csrf
                    <x-ui.field for="signing-file" :label="__('PDF-fil')" error="file" class="sm:col-span-2" :hint="__('Filen kontrolleras innan den sparas. PDF med skript, bifogade filer, lösenord eller åtgärder som skickar uppgifter vidare godtas inte. Den ändras aldrig, så ett dokument med fel laddas upp på nytt.')">
                        <input id="signing-file" name="file" type="file" accept="application/pdf,.pdf" class="{{ $input }} mt-1" required>
                    </x-ui.field>
                    <x-ui.field for="signing-title" :label="__('Rubrik (frivilligt)')" error="title" :hint="__('Annars filens namn. Rubriken visas i listan, i mejlet och på signeringssidan.')">
                        <input id="signing-title" name="title" type="text" class="{{ $input }} mt-1" maxlength="255" value="{{ old('title') }}" autocomplete="off">
                    </x-ui.field>
                    @if($customers->isNotEmpty())
                        <x-ui.field for="signing-customer" :label="__('Hör till kund (frivilligt)')" error="customer_id" :hint="$customers->count() > $pickerLimit ? __('Listan visar de första :count kunderna. Välj ingen kund och koppla dokumentet senare om kunden saknas.', ['count' => $pickerLimit]) : null">
                            <select id="signing-customer" name="customer_id" class="{{ $input }} mt-1">
                                <option value="">{{ __('Ingen') }}</option>
                                @foreach($customers->take($pickerLimit) as $customer)
                                    <option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>{{ $customer->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif
                    @if($suppliers->isNotEmpty())
                        <x-ui.field for="signing-supplier" :label="__('Hör till leverantör (frivilligt)')" error="supplier_id" :hint="$suppliers->count() > $pickerLimit ? __('Listan visar de första :count leverantörerna. Välj ingen leverantör om leverantören saknas.', ['count' => $pickerLimit]) : null">
                            <select id="signing-supplier" name="supplier_id" class="{{ $input }} mt-1">
                                <option value="">{{ __('Ingen') }}</option>
                                @foreach($suppliers->take($pickerLimit) as $supplier)
                                    <option value="{{ $supplier->id }}" @selected((string) old('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif
                    <div class="sm:col-span-2">
                        <x-button>{{ __('Ladda upp och fortsätt') }}</x-button>
                    </div>
                </form>

                @if($recent->isNotEmpty())
                    <div class="mt-6 border-t border-gray-100 pt-4" data-testid="signing-recent-documents">
                        <h3 class="text-sm font-semibold text-gray-900">{{ __('Uppladdade dokument som inte är skickade') }}</h3>
                        <ul class="mt-2 divide-y divide-gray-100 text-sm">
                            @foreach($recent as $recentDocument)
                                <li class="flex flex-wrap items-center gap-3 py-2">
                                    <span class="font-medium text-gray-900">{{ $recentDocument->title }}</span>
                                    <span class="text-gray-600">{{ trans_choice('{1} :count sida|[2,*] :count sidor', $recentDocument->page_count, ['count' => $recentDocument->page_count]) }} · {{ $recentDocument->created_at?->setTimezone($zone)->format('Y-m-d') }}</span>
                                    <a href="{{ route('signing.new', ['typ' => 'DOCUMENT', 'dokument' => $recentDocument->id]) }}" class="ms-auto min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0">{{ __('Välj') }}</a>
                                    <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="deleteDocument('{{ $recentDocument->id }}')" wire:confirm="{{ __('Ta bort dokumentet? Filen raderas och det går inte att ångra.') }}">{{ __('Ta bort') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        </x-ui.card>
    @endif

    @if($step === 2)
        <x-ui.card :title="__('Undertecknare och steg')" :description="__('Undertecknare med samma steg signerar samtidigt. Nästa steg får sin länk först när alla i steget före har signerat. Högst :count undertecknare.', ['count' => $maxSigners])">
            <div class="mt-4 flex flex-wrap gap-3 text-sm">
                <button type="button" class="min-h-11 rounded-xl bg-white px-4 font-medium ring-1 ring-stone-200 hover:bg-gray-50 sm:min-h-0 sm:py-2" wire:click="allAtOnce">{{ __('Alla samtidigt') }}</button>
                <button type="button" class="min-h-11 rounded-xl bg-white px-4 font-medium ring-1 ring-stone-200 hover:bg-gray-50 sm:min-h-0 sm:py-2" wire:click="inOrder">{{ __('I ordning') }}</button>
            </div>
            <x-input-error for="signers" class="mt-2" />
            <ol class="mt-3 space-y-3">
                @foreach($signers as $index => $signer)
                    <li wire:key="wizard-signer-{{ $index }}" class="grid gap-3 rounded-lg border border-gray-200 p-3 sm:grid-cols-[1fr_7rem_auto] sm:items-end">
                        <x-ui.field for="wizard-signer-email-{{ $index }}" :label="__('E-post')" error="signers.{{ $index }}.email">
                            <input id="wizard-signer-email-{{ $index }}" type="email" class="{{ $input }} mt-1" wire:model="signers.{{ $index }}.email" autocomplete="off" required>
                        </x-ui.field>
                        <x-ui.field for="wizard-signer-step-{{ $index }}" :label="__('Steg')" error="signers.{{ $index }}.step">
                            <select id="wizard-signer-step-{{ $index }}" class="{{ $input }} mt-1" wire:model="signers.{{ $index }}.step">
                                @for($number = 1; $number <= $maxStep; $number++)
                                    <option value="{{ $number }}">{{ $number }}</option>
                                @endfor
                            </select>
                        </x-ui.field>
                        @if(count($signers) > 1)
                            <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="removeSigner({{ $index }})">{{ __('Ta bort') }}</button>
                        @endif
                    </li>
                @endforeach
            </ol>
            @if(count($signers) < $maxSigners)
                <button type="button" class="mt-3 min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="addSigner">{{ __('Lägg till undertecknare') }}</button>
            @endif

            <fieldset class="mt-6">
                <legend class="text-sm font-semibold text-gray-900">{{ __('Kopiemottagare') }}</legend>
                <p class="mt-1 text-sm text-gray-600">{{ __('Kopiemottagare signerar inte. De får dokumentet och signeringsbevisen när alla har signerat. Högst :count.', ['count' => $maxCopies]) }}</p>
                <x-input-error for="copies" class="mt-2" />
                @if($copies !== [])
                    <ul class="mt-3 space-y-3">
                        @foreach($copies as $index => $copy)
                            <li wire:key="wizard-copy-{{ $index }}" class="grid gap-3 rounded-lg border border-gray-200 p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                <x-ui.field for="wizard-copy-email-{{ $index }}" :label="__('E-post')" error="copies.{{ $index }}.email">
                                    <input id="wizard-copy-email-{{ $index }}" type="email" class="{{ $input }} mt-1" wire:model="copies.{{ $index }}.email" autocomplete="off" required>
                                </x-ui.field>
                                <x-ui.field for="wizard-copy-name-{{ $index }}" :label="__('Namn (frivilligt)')" error="copies.{{ $index }}.name">
                                    <input id="wizard-copy-name-{{ $index }}" type="text" class="{{ $input }} mt-1" wire:model="copies.{{ $index }}.name" maxlength="120" autocomplete="off">
                                </x-ui.field>
                                <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="removeCopy({{ $index }})">{{ __('Ta bort') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if(count($copies) < $maxCopies)
                    <button type="button" class="mt-3 min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="addCopy">{{ __('Lägg till kopiemottagare') }}</button>
                @endif
                @if($copies !== [])
                    <label class="mt-3 flex items-start gap-3 text-sm">
                        <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model="notifyCopies">
                        <span>{{ __('Meddela kopiemottagarna redan nu att dokumentet är skickat') }}</span>
                    </label>
                @endif
            </fieldset>

            <div class="mt-6 flex flex-wrap items-center gap-4">
                <x-button type="button" wire:click="next">{{ __('Nästa') }}</x-button>
                <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="back">{{ __('Tillbaka') }}</button>
            </div>
        </x-ui.card>
    @endif

    @if($step === 3)
        <x-ui.card :title="__('Kontroller')" :description="__('En kod minskar risken att någon annan använder en vidarebefordrad länk. Underskriften blir fortfarande en enkel elektronisk underskrift.')">
            <ul class="mt-4 space-y-3">
                @foreach($signers as $index => $signer)
                    <li wire:key="wizard-check-{{ $index }}" class="grid gap-3 rounded-lg border border-gray-200 p-3 sm:grid-cols-2">
                        <p class="text-sm font-medium text-gray-900 sm:col-span-2">{{ $signer['email'] }} · {{ __('steg :step', ['step' => $signer['step']]) }}</p>
                        <x-ui.field for="wizard-check-{{ $index }}" :label="__('Kontroll av undertecknaren')" error="signers.{{ $index }}.verification">
                            <select id="wizard-check-{{ $index }}" class="{{ $input }} mt-1" wire:model.live="signers.{{ $index }}.verification">
                                @foreach($checks as $check)
                                    <option value="{{ $check->value }}">{{ $check->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        @if(($signer['verification'] ?? 'NONE') === 'ACCESS_CODE')
                            <x-ui.field for="wizard-access-{{ $index }}" :label="__('Åtkomstkod')" error="signers.{{ $index }}.access_code" :hint="__('Minst 6 tecken, eller 8 om koden bara har siffror. Använd inte personnummer, telefonnummer eller annat som går att ta reda på. Lämna koden till undertecknaren på annat sätt, till exempel per telefon; NONOX skickar den aldrig.')">
                                <div class="mt-1 flex gap-2">
                                    <input id="wizard-access-{{ $index }}" type="text" class="{{ $input }}" wire:model="signers.{{ $index }}.access_code" autocomplete="off" maxlength="64" required>
                                    <button type="button" class="min-h-11 shrink-0 rounded-lg px-3 text-sm font-medium ring-1 ring-gray-300 hover:bg-gray-50 sm:min-h-0" wire:click="suggestCode({{ $index }})">{{ __('Föreslå kod') }}</button>
                                </div>
                            </x-ui.field>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-6 flex flex-wrap items-center gap-4">
                <x-button type="button" wire:click="next">{{ __('Nästa') }}</x-button>
                <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="back">{{ __('Tillbaka') }}</button>
            </div>
        </x-ui.card>
    @endif

    @if($step === 4)
        <x-ui.card :title="__('Tid och påminnelser')">
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <x-ui.field for="wizard-expires" :label="__('Sista dag att signera (frivilligt)')" error="expires_on" :hint="$isQuote ? __('Annars offertens giltighetsdag, eller 30 dagar. Mellan :min och :max dagar fram.', ['min' => $minDays, 'max' => $maxDays]) : __('Annars 30 dagar. Mellan :min och :max dagar fram.', ['min' => $minDays, 'max' => $maxDays])">
                    <input id="wizard-expires" type="date" class="{{ $input }} mt-1" wire:model="expiresOn">
                </x-ui.field>
                @if($deals->isNotEmpty())
                    <x-ui.field for="wizard-deal" :label="__('Affär som vinns när alla har signerat')" error="deal_id">
                        <select id="wizard-deal" class="{{ $input }} mt-1" wire:model="dealId">
                            <option value="">{{ __('Ingen') }}</option>
                            @foreach($deals as $deal)
                                <option value="{{ $deal->id }}">{{ $deal->title }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                @if($canInvoice)
                    <label class="flex items-start gap-3 self-end text-sm">
                        <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model="createInvoice">
                        <span>{{ __('Gör också ett fakturautkast när alla har signerat') }}</span>
                    </label>
                @endif
            </div>
            <fieldset class="mt-4" data-testid="signing-reminder-settings">
                <legend class="text-sm font-semibold text-gray-900">{{ __('Påminnelser') }}</legend>
                <label class="mt-2 flex items-start gap-3 text-sm">
                    <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model.live="autoReminders">
                    <span>{{ __('Påminn de som ska signera automatiskt, mellan 08 och 18 på dagtid. Högst :count påminnelser per person.', ['count' => $maxReminders]) }}</span>
                </label>
                @if($autoReminders)
                    <x-ui.field for="wizard-reminder-days" :label="__('Hur ofta')" error="reminder_days" class="mt-3 max-w-xs">
                        <select id="wizard-reminder-days" class="{{ $input }} mt-1" wire:model="reminderDays">
                            @foreach($intervals as $days)
                                <option value="{{ $days }}">{{ $days === 1 ? __('Varje dag') : __('Var :days:e dag', ['days' => $days]) }}</option>
                            @endforeach
                            <option value="single">{{ __('En gång, två dagar före sista dag') }}</option>
                        </select>
                    </x-ui.field>
                @endif
            </fieldset>
            @if($standalone)
                <fieldset class="mt-4" data-testid="signing-confidential-setting">
                    <legend class="text-sm font-semibold text-gray-900">{{ __('Konfidentiellt') }}</legend>
                    <label class="mt-2 flex items-start gap-3 text-sm">
                        <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model="confidential">
                        <span>{{ __('Gör signeringen konfidentiell. I NONOX syns den då bara för dig och för dem som har behörigheten att hantera signering (som standard ägare, administratörer och redovisningskonsulter), och företagets kopia med alla signeringsbevis går bara till dig. Valet går inte att ändra efteråt. Undertecknarna och kopiemottagarna får dokumentet som vanligt.') }}</span>
                    </label>
                    <x-input-error for="confidential" class="mt-2" />
                </fieldset>
            @endif
            <div class="mt-6 flex flex-wrap items-center gap-4">
                <x-button type="button" wire:click="next">{{ __('Nästa') }}</x-button>
                <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="back">{{ __('Tillbaka') }}</button>
            </div>
        </x-ui.card>
    @endif

    @if($step === 5)
        <x-ui.card :title="__('Granska och skicka')" :description="__('Kontrollera uppgifterna. När du har skickat går de som är i första steget en länk till sin e-post, och dokumentet kan inte ändras.')">
            <dl class="mt-4 space-y-3 text-sm" data-testid="signing-wizard-review">
                <div>
                    <dt class="font-semibold text-gray-900">{{ __('Dokument') }}</dt>
                    <dd class="text-gray-700">{{ $document?->signingTitle() }}@if($pages) · {{ trans_choice('{1} :count sida|[2,*] :count sidor', $pages, ['count' => $pages]) }}@endif</dd>
                </div>
                <div>
                    <dt class="font-semibold text-gray-900">{{ __('Undertecknare') }}</dt>
                    <dd>
                        <ol class="text-gray-700">
                            @foreach($signers as $signer)
                                <li>{{ __('Steg :step', ['step' => $signer['step']]) }} · {{ $signer['email'] }} · {{ $verificationLabel($signer['verification'] ?? 'NONE') }}</li>
                            @endforeach
                        </ol>
                    </dd>
                </div>
                @if($copies !== [])
                    <div>
                        <dt class="font-semibold text-gray-900">{{ __('Kopiemottagare') }}</dt>
                        <dd class="text-gray-700">{{ collect($copies)->pluck('email')->implode(', ') }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="font-semibold text-gray-900">{{ __('Sista dag att signera') }}</dt>
                    <dd class="text-gray-700">{{ $expiresOn !== '' ? $expiresOn : __('Enligt standard') }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-gray-900">{{ __('Påminnelser') }}</dt>
                    <dd class="text-gray-700">{{ $autoReminders ? ($reminderDays === 'single' ? __('En gång, två dagar före sista dag') : ($reminderDays === '1' ? __('Varje dag') : __('Var :days:e dag', ['days' => $reminderDays]))) : __('Inga automatiska påminnelser') }}</dd>
                </div>
                @if($standalone && $confidential)
                    <div>
                        <dt class="font-semibold text-gray-900">{{ __('Konfidentiellt') }}</dt>
                        <dd class="text-gray-700">{{ __('Ja, bara du och den som hanterar signering ser den.') }}</dd>
                    </div>
                @endif
            </dl>
            <x-input-error for="document" class="mt-3" />
            <x-input-error for="signing" class="mt-3" />
            <div class="mt-6 flex flex-wrap items-center gap-4">
                <x-button type="button" wire:click="send" wire:loading.attr="disabled">{{ __('Skicka för signering') }}</x-button>
                <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="back">{{ __('Tillbaka') }}</button>
            </div>
        </x-ui.card>
    @endif
</div>
