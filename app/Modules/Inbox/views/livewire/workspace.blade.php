@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm text-sm';
    $cell = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-md text-sm py-1';
    $tabs = ['att-granska' => __('Att granska'), 'utkast-klara' => __('Utkast klara'), 'bokfort' => __('Bokfört'), 'avvisat' => __('Avvisat')];
    $kind = $form['kind'] ?? null;
    $open = $message !== null && $message->status->isOpen();
@endphp

<div class="space-y-6"
    x-data="{
        help: false,
        typing(event) { return ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName) },
        keys(event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') { event.preventDefault(); $wire.approve('post'); return; }
            if (event.key === 'Escape') { this.help = false; return; }
            if (this.typing(event) || event.ctrlKey || event.metaKey || event.altKey) { return; }
            if (event.key === '?') { this.help = ! this.help; }
            if (event.key === 'j') { $wire.step(1); }
            if (event.key === 'k') { $wire.step(-1); }
            if (event.key === 'g') { $wire.allowSender(); }
            if (event.key === 'x') { $wire.reject(); }
        },
    }"
    x-on:keydown.window="keys($event)">

    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-5 py-4 text-sm">
        <p>{{ __('Företagets adress:') }} <code class="font-mono text-gray-900">{{ $address }}</code></p>
        <button type="button" class="text-sm font-medium text-accent-700 hover:underline" x-on:click="help = true">{{ __('Kortkommandon (?)') }}</button>
    </div>

    @if($unassigned->isNotEmpty() && $canManage)
        <x-ui.card :title="__('Väntar på att ett företag hämtar dem')">
            <p class="text-sm text-gray-600">{{ __('E-post till den gemensamma adressen från en avsändare som flera av dina företag har godkänt. Hämta det som gäller det här företaget.') }}</p>
            <ul class="mt-3 divide-y divide-gray-100">
                @foreach($unassigned as $waiting)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm" wire:key="unassigned-{{ $waiting->id }}">
                        <span><span class="font-medium text-gray-900">{{ $waiting->subject ?: __('(inget ämne)') }}</span>
                            <span class="block text-xs text-gray-500">{{ $waiting->from_email }} · {{ $waiting->received_at->timezone('Europe/Stockholm')->format('Y-m-d H:i') }}</span></span>
                        <button type="button" wire:click="claim('{{ $waiting->id }}')" class="rounded-lg border border-gray-300 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-50">{{ __('Hämta till det här företaget') }}</button>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Flikar') }}">
            @foreach($tabs as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
                    'rounded-lg px-3 py-1.5 text-sm font-medium',
                    'bg-ink text-white' => $tab === $key,
                    'bg-white text-gray-700 border border-gray-200 hover:bg-gray-50' => $tab !== $key,
                ]) aria-current="{{ $tab === $key ? 'page' : 'false' }}">{{ $label }} <span class="tabular-nums">({{ $counts[$key] }})</span></button>
            @endforeach
        </nav>
        <label for="inbox_search" class="sr-only">{{ __('Sök') }}</label>
        <input id="inbox_search" type="search" class="{{ $input }} sm:max-w-xs" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på avsändare eller ämne') }}">
    </div>

    @if($messages->isEmpty())
        <x-ui.empty-state :title="__('Inget här')">
            {{ $tab === 'att-granska' ? __('Skicka en dagsrapport, faktura eller ett kvitto till adressen ovan, så dyker det upp här.') : __('Inga underlag i den här fliken.') }}
        </x-ui.empty-state>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                    <tr><th class="px-4 py-2">{{ __('Mottaget') }}</th><th class="px-4 py-2">{{ __('Avsändare') }}</th><th class="px-4 py-2">{{ __('Ämne') }}</th><th class="px-4 py-2">{{ __('Typ') }}</th><th class="px-4 py-2">{{ __('Status') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($messages as $row)
                        <tr wire:key="message-{{ $row->id }}" wire:click="select('{{ $row->id }}')" @class(['cursor-pointer hover:bg-gray-50', 'bg-accent-50' => $row->id === $selected])>
                            <td class="whitespace-nowrap px-4 py-2 tabular-nums">{{ $row->received_at->timezone('Europe/Stockholm')->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-2">{{ $row->from_email }}</td>
                            <td class="px-4 py-2">{{ $row->subject ?: __('(inget ämne)') }} @if($row->attachments_count > 0)<span class="text-gray-500">· {{ $row->attachments_count === 1 ? __('1 bilaga') : __(':count bilagor', ['count' => $row->attachments_count]) }}</span>@endif</td>
                            <td class="px-4 py-2">{{ $row->document_type?->label() ?? '–' }}</td>
                            <td class="px-4 py-2">
                                <x-ui.badge :tone="$row->status === App\Modules\Inbox\Domain\MessageStatus::DraftReady ? 'success' : ($row->status === App\Modules\Inbox\Domain\MessageStatus::Quarantine ? 'accent' : 'neutral')">{{ $row->status->label() }}</x-ui.badge>
                                @if($row->warnings !== [])<span class="ml-1 text-xs text-accent-700">{{ count($row->warnings) === 1 ? __('1 varning') : __(':count varningar', ['count' => count($row->warnings)]) }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $messages->links() }}
    @endif

    @if($message)
        <div class="grid gap-6 xl:grid-cols-2" wire:key="detail-{{ $message->id }}">
            {{-- Left: the original and its attachments. Nothing is rendered as HTML on this origin. --}}
            <x-ui.card :title="$message->subject ?: __('(inget ämne)')">
                <p class="text-sm text-gray-600">{{ __('Från :sender till :recipient, :time', ['sender' => $message->senderLabel(), 'recipient' => $message->recipient, 'time' => $message->received_at->timezone('Europe/Stockholm')->format('Y-m-d H:i')]) }}</p>
                <p class="mt-1 text-xs text-gray-500">SPF {{ $message->auth['spf'] ?? '–' }} · DKIM {{ $message->auth['dkim'] ?? '–' }} · DMARC {{ $message->auth['dmarc'] ?? '–' }} · <a class="underline" href="{{ route('inbox.original', ['message' => $message->id]) }}">{{ __('Ladda ner originalet (.eml)') }}</a></p>

                @php($first = $message->attachments->first(fn ($attachment) => $attachment->isPreviewable()))
                @if($first)
                    <div class="mt-4 overflow-hidden rounded-lg border border-gray-200">
                        @if($first->mime === 'application/pdf')
                            <iframe src="{{ route('inbox.attachment', ['message' => $message->id, 'attachment' => $first->id]) }}" title="{{ $first->filename }}" class="h-[36rem] w-full" sandbox></iframe>
                        @else
                            <img src="{{ route('inbox.attachment', ['message' => $message->id, 'attachment' => $first->id]) }}" alt="{{ $first->filename }}" class="mx-auto max-h-[36rem]">
                        @endif
                    </div>
                @endif

                @if($message->attachments->isNotEmpty())
                    <ul class="mt-4 space-y-1 text-sm">
                        @foreach($message->attachments as $attachment)
                            <li>
                                @if($attachment->isBlocked())
                                    <span class="text-red-700">{{ $attachment->filename }} – {{ __('spärrad: :reason', ['reason' => $attachment->blocked_reason]) }}</span>
                                @else
                                    <a class="font-medium text-accent-700 underline" href="{{ route('inbox.attachment', ['message' => $message->id, 'attachment' => $attachment->id]) }}" target="_blank" rel="noopener">{{ $attachment->filename }}</a>
                                    <span class="text-gray-500">{{ $attachment->humanSize() }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($message->body_text)
                    <pre class="mt-4 max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-800">{{ $message->body_text }}</pre>
                @endif
            </x-ui.card>

            {{-- Right: why it is here, the proposal and the decisions. --}}
            <x-ui.card :title="$message->status->label()">
                <x-slot:action>
                    <button type="button" wire:click="close" class="text-sm text-gray-600 hover:underline">{{ __('Stäng') }}</button>
                </x-slot:action>

                @if($message->quarantine_reasons !== [])
                    <div class="rounded-lg border border-accent-200 bg-accent-50 p-3 text-sm text-accent-900">
                        <p class="font-semibold">{{ __('I karantän. Inget tolkas förrän du har tittat på det.') }}</p>
                        <ul class="mt-1 list-disc ps-5">@foreach($message->quarantine_reasons as $reason)<li>{{ $reason['message'] }}</li>@endforeach</ul>
                        @if($canManage)
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if($message->hasQuarantineReason('NOT_ALLOWLISTED'))
                                    <button type="button" wire:click="allowSender" class="rounded-lg bg-accent-600 px-3 py-1.5 font-semibold text-white hover:bg-accent-700">{{ __('Godkänn avsändaren') }}</button>
                                @else
                                    <button type="button" wire:click="release" class="rounded-lg border border-accent-300 bg-white px-3 py-1.5 font-semibold text-accent-800 hover:bg-accent-100">{{ __('Släpp igenom efter granskning') }}</button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif

                @if($message->warnings !== [])
                    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900">
                        <p class="font-semibold">{{ __('Kontrollera innan du godkänner. Underlaget bokförs aldrig automatiskt med varningar.') }}</p>
                        <ul class="mt-1 list-disc ps-5">@foreach($message->warnings as $warning)<li>{{ $warning['message'] }}</li>@endforeach</ul>
                        @if($open && $message->status !== App\Modules\Inbox\Domain\MessageStatus::Quarantine)
                            <label class="mt-2 flex items-center gap-2"><input type="checkbox" wire:model="warningsConfirmed" class="rounded border-red-300 text-red-700 focus:ring-red-500"> {{ __('Jag har kontrollerat varningarna') }}</label>
                            <x-input-error for="warnings" class="mt-1" />
                        @endif
                    </div>
                @endif

                <p class="mt-3 text-sm text-gray-600">
                    {{ __('Typ: :type', ['type' => $message->document_type?->label() ?? __('okänd')]) }}
                    @if($message->interpreted_by) · {{ __('Tolkat av :by', ['by' => $message->interpreted_by->label()]) }}@endif
                    @if($message->template) · {{ __('Mall: :name', ['name' => $message->template->name]) }}@endif
                </p>
                @if(($form['notes'] ?? '') !== '')<p class="mt-1 text-sm text-gray-600">{{ $form['notes'] }}</p>@endif
                <x-input-error for="form" class="mt-2" />
                <x-input-error for="proposal" class="mt-2" />
                <x-input-error for="message" class="mt-2" />

                @if($open && $message->status !== App\Modules\Inbox\Domain\MessageStatus::Quarantine && $canManage)
                    @if($kind === null)
                        <div class="mt-4 rounded-lg bg-gray-50 p-4 text-sm">
                            <p class="font-medium text-gray-900">{{ __('Ingen tolkning finns. Hur ska underlaget bokföras?') }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" wire:click="startAs('vouchers')" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium hover:bg-gray-50">{{ __('Som verifikation') }}</button>
                                <button type="button" wire:click="startAs('supplier_invoice')" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium hover:bg-gray-50">{{ __('Som leverantörsfaktura') }}</button>
                                @if($readableReport)
                                    <button type="button" wire:click="startAs('daily_report')" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-medium hover:bg-gray-50">{{ __('Som dagsrapport') }}</button>
                                @endif
                            </div>
                        </div>
                    @elseif($kind === 'daily_report')
                        @include('inbox::livewire.partials.daily-report')
                    @elseif($kind === 'vouchers')
                        @include('inbox::livewire.partials.vouchers')
                    @elseif($kind === 'supplier_invoice')
                        @include('inbox::livewire.partials.supplier-invoice')
                    @endif

                    @if($kind !== null)
                        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                            @if($confirmingApproval)
                                <p class="w-full text-sm text-gray-700">{{ $kind === 'supplier_invoice' ? __('Fakturan registreras och bokförs. Det går inte att ändra efteråt, bara krediteras.') : __('Verifikationen bokförs och får ett nummer. En bokförd verifikation kan aldrig ändras, bara rättas.') }}</p>
                                <button type="button" wire:click="approve('post')" class="rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700" autofocus>{{ __('Ja, bokför') }}</button>
                                <button type="button" wire:click="$set('confirmingApproval', false)" class="rounded-lg px-3 py-2 text-sm text-gray-700 hover:underline">{{ __('Avbryt') }}</button>
                            @else
                                <button type="button" wire:click="approve('post')" class="rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Godkänn och bokför') }} <span class="opacity-75">(Ctrl+Enter)</span></button>
                                <button type="button" wire:click="approve('draft')" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Spara som utkast') }}</button>
                            @endif
                        </div>
                    @endif
                @endif

                @if($open && $canManage)
                    <div class="mt-4 border-t border-gray-100 pt-4">
                        @if($confirmingRejection)
                            <x-ui.field for="rejection_reason" :label="__('Varför avvisas underlaget? (frivilligt)')">
                                <input id="rejection_reason" type="text" class="{{ $input }} mt-1" wire:model="rejectionReason" maxlength="500">
                            </x-ui.field>
                            <div class="mt-2 flex gap-2">
                                <button type="button" wire:click="reject" class="rounded-lg bg-red-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-800">{{ __('Ja, avvisa') }}</button>
                                <button type="button" wire:click="$set('confirmingRejection', false)" class="rounded-lg px-3 py-1.5 text-sm text-gray-700 hover:underline">{{ __('Avbryt') }}</button>
                            </div>
                        @else
                            <div class="flex flex-wrap gap-4">
                                <button type="button" wire:click="reject" class="text-sm font-medium text-red-700 hover:underline">{{ __('Avvisa (x)') }}</button>
                                @if($message->status !== App\Modules\Inbox\Domain\MessageStatus::Quarantine)
                                    <button type="button" wire:click="interpretAgain" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Läs igen') }}</button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif

                @if($canManage && ! in_array($message->status, [App\Modules\Inbox\Domain\MessageStatus::Booked, App\Modules\Inbox\Domain\MessageStatus::DraftCreated], true))
                    <div class="mt-4 border-t border-gray-100 pt-4">
                        <button type="button" wire:click="delete" wire:confirm="{{ __('Radera mejlet och dess bilagor för gott? Det går inte att ångra.') }}" class="text-sm font-medium text-red-700 hover:underline">{{ __('Radera') }}</button>
                    </div>
                @endif

                @if($canManage && $message->quarantine_reasons === [] && ! $message->failedAuthentication())
                    <form wire:submit="reply" class="mt-4 space-y-2 border-t border-gray-100 pt-4">
                        <x-ui.field for="reply_text" :label="__('Svara avsändaren')" error="replyText">
                            <textarea id="reply_text" rows="3" class="{{ $input }} mt-1" wire:model="replyText" maxlength="2000" placeholder="{{ __('Till exempel: Kvittot saknas, kan du skicka det?') }}"></textarea>
                        </x-ui.field>
                        <button type="submit" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Skicka svar') }}</button>
                    </form>
                @endif

                @if(! $open)
                    <p class="mt-4 text-sm text-gray-600">
                        @if($message->handler){{ __('Hanterat av :name :time.', ['name' => $message->handler->name, 'time' => $message->handled_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i')]) }}@endif
                        @if($message->rejection_reason) {{ __('Skäl: :reason', ['reason' => $message->rejection_reason]) }}@endif
                    </p>
                    @foreach($message->voucher_ids as $voucherId)
                        <a href="{{ route('accounting.vouchers.show', ['voucher' => $voucherId]) }}" class="mt-1 block text-sm font-medium text-accent-700 underline">{{ __('Öppna verifikationen') }}</a>
                    @endforeach
                    @if($message->supplier_invoice_id)
                        <a href="{{ route('payables.invoices.show', ['supplierInvoice' => $message->supplier_invoice_id]) }}" class="mt-1 block text-sm font-medium text-accent-700 underline">{{ __('Öppna leverantörsfakturan') }}</a>
                    @endif
                @endif
            </x-ui.card>
        </div>
    @endif

    <div x-show="help" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" x-on:click.self="help = false" role="dialog" aria-modal="true" aria-label="{{ __('Kortkommandon') }}">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
            <h2 class="text-lg font-semibold text-ink">{{ __('Kortkommandon') }}</h2>
            <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="font-mono">Ctrl/⌘ + Enter</dt><dd>{{ __('Godkänn och bokför (tryck igen för att bekräfta)') }}</dd>
                <dt class="font-mono">j / k</dt><dd>{{ __('Nästa / föregående underlag') }}</dd>
                <dt class="font-mono">g</dt><dd>{{ __('Godkänn avsändaren') }}</dd>
                <dt class="font-mono">x</dt><dd>{{ __('Avvisa (tryck igen för att bekräfta)') }}</dd>
                <dt class="font-mono">?</dt><dd>{{ __('Visa eller dölj kortkommandona') }}</dd>
                <dt class="font-mono">Esc</dt><dd>{{ __('Stäng') }}</dd>
            </dl>
        </div>
    </div>
</div>
