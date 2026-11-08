@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $money = fn (int $ore) => App\Support\Money\Money::ofOre($ore);
    $zone = 'Europe/Stockholm';
    $isOpen = $deal->isOpen();
    $weighted = App\Modules\Crm\Domain\Forecast::percentOf($deal->value_ore * (int) ($deal->stage?->probability ?? 0));
@endphp

<div class="space-y-6">
    {{-- Header: what, for whom, how much and where it stands. --}}
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-sm text-gray-500">
                    <a href="{{ route('crm.accounts.show', $deal->account_id) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $deal->account?->name }}</a>
                    @if($deal->person) · <a href="{{ route('crm.people.show', $deal->person) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $deal->person->name }}</a>@endif
                    · {{ $deal->pipeline?->name }}
                </p>
                <h1 class="mt-1 break-words text-2xl font-bold tracking-tight text-ink">{{ $deal->title }}</h1>
                @if($deal->person?->doNotContact())
                    <p class="mt-2 rounded-xl bg-accent-50 px-3 py-2 text-sm text-accent-800" data-testid="crm-deal-do-not-contact">
                        {{ __(':name är märkt Kontakta inte. Nästa steg planeras utan personen. Byt kontaktperson under Ändra, eller avsluta affären.', ['name' => $deal->person->name]) }}
                    </p>
                @endif
                <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-gray-600">
                    <x-ui.badge :tone="$deal->status->tone()">{{ $deal->status->label() }}</x-ui.badge>
                    <span>{{ __('Ansvarig') }}: {{ $deal->owner?->name ?? __('ingen') }}</span>
                </p>
            </div>
            @if($canManage && $isOpen)
                <div class="flex flex-wrap gap-3 text-sm">
                    <button type="button" class="inline-flex min-h-11 items-center rounded-xl bg-green-700 px-4 font-semibold text-white hover:bg-green-800" wire:click="startWin">{{ __('Vunnen') }}</button>
                    <button type="button" class="inline-flex min-h-11 items-center rounded-xl bg-white px-4 font-semibold text-ink ring-1 ring-stone-300 hover:ring-stone-400" wire:click="startLose">{{ __('Förlorad') }}</button>
                    <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700" wire:click="startEdit">{{ __('Ändra') }}</button>
                </div>
            @endif
        </div>

        <dl class="mt-5 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-gray-500">{{ __('Värde') }}</dt>
                <dd><x-ui.amount :money="$money($deal->value_ore)" class="text-lg font-bold text-ink" /></dd>
            </div>
            @if($isOpen)
                <div>
                    <dt class="text-gray-500">{{ __('Viktat (:percent %)', ['percent' => $deal->stage?->probability ?? 0]) }}</dt>
                    <dd><x-ui.amount :money="$money($weighted)" class="text-lg font-bold text-ink" /></dd>
                </div>
            @endif
            <div>
                <dt class="text-gray-500">{{ $isOpen ? __('Väntat avslut') : ($deal->won_at ? __('Vunnen') : __('Förlorad')) }}</dt>
                <dd class="text-lg font-bold text-ink">
                    @if($isOpen)
                        {{ $deal->expected_close_date?->translatedFormat('j M Y') ?? '–' }}
                    @else
                        {{ ($deal->won_at ?? $deal->lost_at)?->setTimezone($zone)->translatedFormat('j M Y') }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('Steg') }}</dt>
                <dd class="text-lg font-bold text-ink">{{ $deal->stage?->name ?? '–' }}</dd>
            </div>
        </dl>

        @if($deal->status === App\Modules\Crm\Domain\DealStatus::Lost)
            <div class="mt-5 rounded-2xl bg-stone-50 p-4 text-sm">
                <p><span class="font-medium text-ink">{{ __('Orsak') }}:</span> {{ $deal->lossReason?->name ?? '–' }}</p>
                @if($deal->loss_note)<p class="mt-1 whitespace-pre-line text-gray-700">{{ $deal->loss_note }}</p>@endif
                <p class="mt-2 flex flex-wrap items-center gap-3">
                    <span>{{ $deal->win_back_at ? __('Vinn tillbaka: :date', ['date' => $deal->win_back_at->translatedFormat('j F Y')]) : __('Ingen påminnelse om att vinna tillbaka kunden.') }}</span>
                    @if($canManage)
                        <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="startWinBack">{{ __('Ändra datum') }}</button>
                    @endif
                </p>
            </div>
        @endif

        @if($deal->status === App\Modules\Crm\Domain\DealStatus::Won && ! $limitedLedger && ($deal->quote || $deal->order || $deal->invoice))
            <p class="mt-5 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                <span class="text-gray-500">{{ __('Kopplad till') }}:</span>
                @foreach([['doc' => $deal->quote, 'route' => 'invoicing.quotes.show', 'label' => __('Offert :number')], ['doc' => $deal->order, 'route' => 'invoicing.orders.show', 'label' => __('Order :number')], ['doc' => $deal->invoice, 'route' => 'invoicing.invoices.show', 'label' => __('Faktura :number')]] as $link)
                    @continue(! $link['doc'])
                    @php $text = str_replace(':number', (string) ($link['doc']->number ?? __('utkast')), $link['label']); @endphp
                    @if($canSeeInvoices)
                        <a href="{{ route($link['route'], $link['doc']->id) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $text }}</a>
                    @else
                        <span>{{ $text }}</span>
                    @endif
                @endforeach
            </p>
        @endif
    </x-ui.card>

    @if($canMakeQuote)
        <div class="rounded-3xl bg-white p-6 shadow-xs ring-1 ring-stone-200" data-testid="crm-deal-new-quote">
            <x-button type="button" wire:click="newQuote" wire:loading.attr="disabled">{{ __('Ny offert') }}</x-button>
            <p class="mt-2 text-sm text-gray-600">{{ __('Offerten får en rad från affären och kopplas till den. Raderna går att ändra innan du skickar den.') }}</p>
            <x-input-error for="deal" class="mt-1" />
        </div>
        <livewire:crm.quote-from-template :deal-id="$deal->id" :boxed="true" :key="'qft-deal-'.$deal->id" />
    @endif

    {{-- The stages: one tap moves the deal. --}}
    @if($isOpen)
        <nav aria-label="{{ __('Steg i säljtratten') }}">
            <ol class="flex gap-2 overflow-x-auto pb-1">
                @foreach($stages as $stage)
                    <li class="flex-none">
                        @if($canManage && $stage->id !== $deal->stage_id)
                            <button type="button" class="inline-flex min-h-11 items-center rounded-xl bg-white px-4 text-sm font-medium text-ink ring-1 ring-stone-200 hover:ring-stone-400" wire:click="moveTo({{ $stage->id }})">{{ $stage->name }}</button>
                        @else
                            <span @class(['inline-flex min-h-11 items-center rounded-xl px-4 text-sm font-medium', 'bg-ink text-white' => $stage->id === $deal->stage_id, 'bg-white text-gray-500 ring-1 ring-stone-200' => $stage->id !== $deal->stage_id]) @if($stage->id === $deal->stage_id) aria-current="step" @endif>{{ $stage->name }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    {{-- The open panel. --}}
    @if($panel === 'edit')
        <x-ui.card :title="__('Ändra affären')">
            <form wire:submit="saveEdit" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="edit_title" :label="__('Rubrik')" error="title" class="sm:col-span-2">
                    <input id="edit_title" type="text" class="{{ $input }} mt-1" wire:model="edit.title">
                </x-ui.field>
                <x-ui.field for="edit_value" :label="__('Värde i kronor, utan moms')" :error="$errors->has('value') ? 'value' : 'value_ore'">
                    <input id="edit_value" type="text" inputmode="decimal" class="{{ $input }} mt-1 tabular-nums" wire:model="edit.value">
                </x-ui.field>
                <x-ui.field for="edit_close" :label="__('Väntat avslut')" error="expected_close_date">
                    <input id="edit_close" type="date" class="{{ $input }} mt-1" wire:model="edit.expected_close_date">
                </x-ui.field>
                <x-ui.field for="edit_owner" :label="__('Ansvarig')" error="owner_id">
                    <select id="edit_owner" class="{{ $input }} mt-1" wire:model="edit.owner_id">
                        <option value="">{{ __('Ingen') }}</option>
                        @foreach($members as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="edit_person" :label="__('Kontaktperson')" error="person_id">
                    <select id="edit_person" class="{{ $input }} mt-1" wire:model="edit.person_id">
                        <option value="">{{ __('Ingen särskild') }}</option>
                        @foreach($people as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @elseif($panel === 'step' || $panel === 'complete')
        <x-ui.card :title="$panel === 'step' ? __('Nytt steg') : __('Klarmarkera')" :description="$panel === 'complete' && $nextRequired ? __('Det här är affärens enda planerade steg. Planera nästa nu, eller markera affären som vunnen eller förlorad.') : null">
            <form wire:submit="{{ $panel === 'step' ? 'saveStep' : 'complete' }}" class="grid gap-4 sm:grid-cols-2">
                @if($panel === 'complete')
                    @if($completion['call_outcome'] !== '')
                        <x-ui.field for="completion_outcome" :label="__('Hur gick samtalet?')" error="call_outcome">
                            <select id="completion_outcome" class="{{ $input }} mt-1" wire:model="completion.call_outcome">
                                @foreach($outcomes as $outcome)
                                    <option value="{{ $outcome->value }}">{{ $outcome->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif
                    <x-ui.field for="completion_note" :label="__('Hur gick det? (valfritt)')" error="note" class="sm:col-span-2">
                        <textarea id="completion_note" rows="2" class="{{ $input }} mt-1" wire:model="completion.note"></textarea>
                    </x-ui.field>
                @endif
                @if($panel === 'step' || $nextRequired)
                    <fieldset class="grid gap-4 rounded-2xl bg-stone-50 p-4 sm:col-span-2 sm:grid-cols-2">
                        <legend class="px-1 text-sm font-semibold text-ink">{{ __('Nästa steg') }}</legend>
                        <x-ui.field for="step_type" :label="__('Typ')" :error="$errors->has('next.type') ? 'next.type' : 'type'">
                            <select id="step_type" class="{{ $input }} mt-1" wire:model="step.type">
                                @foreach($types as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="step_subject" :label="__('Vad')" error="subject">
                            <input id="step_subject" type="text" class="{{ $input }} mt-1" wire:model="step.subject" placeholder="{{ __('Till exempel: skicka offerten') }}">
                        </x-ui.field>
                        <x-ui.field for="step_due" :label="__('När')" error="due_at">
                            <input id="step_due" type="datetime-local" class="{{ $input }} mt-1" wire:model="step.due_at">
                        </x-ui.field>
                        <x-ui.field for="step_assigned" :label="__('Vem gör det')" error="assigned_to">
                            <select id="step_assigned" class="{{ $input }} mt-1" wire:model="step.assigned_to">
                                @foreach($members as $member)
                                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        @error('next')<p class="text-sm text-accent-700 sm:col-span-2">{{ $message }}</p>@enderror
                        @error('person_id')<p class="text-sm text-accent-700 sm:col-span-2">{{ $message }}</p>@enderror
                    </fieldset>
                @endif
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ $panel === 'step' ? __('Planera') : ($nextRequired ? __('Klar och planera nästa') : __('Klar')) }}</x-button>
                    @if($panel === 'complete' && $nextRequired)
                        <button type="button" class="min-h-11 text-sm font-medium text-green-800 underline" wire:click="startWin">{{ __('Affären är vunnen') }}</button>
                        <button type="button" class="min-h-11 text-sm font-medium text-gray-700 underline" wire:click="startLose">{{ __('Affären är förlorad') }}</button>
                    @endif
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @elseif($panel === 'win')
        <x-ui.card :title="__('Vunnen affär')" :description="__('Justera värdet till det ni kom överens om. Koppla gärna offerten, ordern eller fakturan, så syns affären i bokföringen.')">
            <form wire:submit="saveWin" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="win_value" :label="__('Värde i kronor, utan moms')" error="value_ore">
                    <input id="win_value" type="text" inputmode="decimal" class="{{ $input }} mt-1 tabular-nums" wire:model="win.value">
                </x-ui.field>
                @if($quotes->isNotEmpty())
                    <x-ui.field for="win_quote" :label="__('Offert')" error="quote_id">
                        <select id="win_quote" class="{{ $input }} mt-1" wire:model="win.quote_id">
                            <option value="">{{ __('Ingen') }}</option>
                            @foreach($quotes as $quote)
                                <option value="{{ $quote->id }}">{{ __('Offert :number', ['number' => $quote->number]) }} · {{ $quote->document_date->translatedFormat('j M Y') }} · {{ $quote->net_ore->format() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                @if($orders->isNotEmpty())
                    <x-ui.field for="win_order" :label="__('Order')" error="order_id">
                        <select id="win_order" class="{{ $input }} mt-1" wire:model="win.order_id">
                            <option value="">{{ __('Ingen') }}</option>
                            @foreach($orders as $order)
                                <option value="{{ $order->id }}">{{ __('Order :number', ['number' => $order->number]) }} · {{ $order->document_date->translatedFormat('j M Y') }} · {{ $order->net_ore->format() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                @if($invoices->isNotEmpty())
                    <x-ui.field for="win_invoice" :label="__('Faktura')" error="invoice_id">
                        <select id="win_invoice" class="{{ $input }} mt-1" wire:model="win.invoice_id">
                            <option value="">{{ __('Ingen') }}</option>
                            @foreach($invoices as $invoice)
                                <option value="{{ $invoice->id }}">{{ __('Faktura :number', ['number' => $invoice->number]) }} · {{ $invoice->invoice_date->translatedFormat('j M Y') }} · {{ $invoice->net_ore->format() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                @if($canMakeCustomer)
                    <label class="flex min-h-11 items-center gap-3 text-sm text-gray-800 sm:col-span-2">
                        <input type="checkbox" class="size-5 rounded-sm border-gray-300 text-ink focus:ring-accent-500" wire:model="win.make_customer">
                        <span>{{ __('Gör :name till kund, så att offert, order och faktura kan skapas', ['name' => $deal->account?->name]) }}</span>
                    </label>
                @endif
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Markera som vunnen') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @elseif($panel === 'lose')
        <x-ui.card :title="__('Förlorad affär')" :description="__('Välj varför, så går det att se mönster. Datumet ger dig en påminnelse om att höra av dig igen.')">
            <form wire:submit="saveLose" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="lose_reason" :label="__('Orsak')" error="loss_reason_id">
                    <select id="lose_reason" class="{{ $input }} mt-1" wire:model="lose.loss_reason_id">
                        <option value="">{{ __('Välj orsak') }}</option>
                        @foreach($reasons as $reason)
                            <option value="{{ $reason->id }}">{{ $reason->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="lose_win_back" :label="__('Vinn tillbaka')" error="win_back_at" :hint="__('Lämna tomt om du inte vill ha någon påminnelse.')">
                    <input id="lose_win_back" type="date" class="{{ $input }} mt-1" wire:model="lose.win_back_at">
                </x-ui.field>
                <x-ui.field for="lose_note" :label="__('Kommentar (valfritt)')" error="loss_note" class="sm:col-span-2">
                    <textarea id="lose_note" rows="2" class="{{ $input }} mt-1" wire:model="lose.loss_note"></textarea>
                </x-ui.field>
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Markera som förlorad') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @elseif($panel === 'winback')
        <x-ui.card :title="__('Vinn tillbaka')">
            <form wire:submit="saveWinBack" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="winback_date" :label="__('Datum')" error="win_back_at" :hint="__('Lämna tomt om du inte vill ha någon påminnelse.')">
                    <input id="winback_date" type="date" class="{{ $input }} mt-1" wire:model="winBackAt">
                </x-ui.field>
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card :title="__('Nästa steg')">
                <x-slot:action>
                    @if($canManage && $isOpen)
                        <button type="button" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="startStep">{{ __('Nytt steg') }}</button>
                    @endif
                </x-slot:action>
                @if($open->isEmpty())
                    <p @class(['text-sm', 'font-medium text-accent-700' => $isOpen, 'text-gray-600' => ! $isOpen])>{{ $isOpen ? __('Nästa steg saknas. Planera ett, så att affären inte glöms bort.') : __('Inget planerat.') }}</p>
                @else
                    <ul class="divide-y divide-gray-100" data-testid="crm-deal-steps">
                        @foreach($open as $openStep)
                            @php $late = $openStep->isOverdue($now); @endphp
                            <li wire:key="step-{{ $openStep->id }}" class="flex flex-wrap items-center gap-3 py-3">
                                <span class="min-w-0 flex-1 basis-48">
                                    <span class="block font-medium text-gray-900">{{ $openStep->subject }}</span>
                                    <span @class(['block text-sm', 'font-medium text-accent-700' => $late, 'text-gray-600' => ! $late])>
                                        {{ $openStep->type->label() }}@if($openStep->due_at) · {{ $openStep->due_at->setTimezone($zone)->translatedFormat('j M Y H:i') }}@if($late) · {{ __('försenad') }}@endif @endif
                                        @if($openStep->person) · {{ $openStep->person->name }}@endif
                                        @if($openStep->assignee) · {{ $openStep->assignee->name }}@endif
                                    </span>
                                </span>
                                @if($canManage)
                                    <button type="button" class="inline-flex min-h-11 items-center rounded-xl bg-ink px-4 text-sm font-semibold text-white hover:bg-black" wire:click="startCompletion({{ $openStep->id }})">{{ __('Klar') }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card :title="__('Historik')">
            @if($history->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Inget har hänt ännu.') }}</p>
            @else
                <ol class="space-y-3 text-sm" data-testid="crm-deal-history">
                    @foreach($history as $entry)
                        <li class="flex gap-3">
                            <span @class(['mt-1.5 size-2.5 flex-none rounded-full', 'bg-accent-500' => $entry['kind'] === 'activity', 'bg-stone-400' => $entry['kind'] !== 'activity'])></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-gray-900">{{ $entry['text'] }}</span>
                                <span class="block text-xs text-gray-500">{{ $entry['at']->setTimezone($zone)->translatedFormat('j M Y H:i') }}@if($entry['user']) · {{ $entry['user'] }}@endif</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.card>
    </div>
</div>
