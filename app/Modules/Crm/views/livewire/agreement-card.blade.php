@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $status = $agreement?->status($today);
    $deadline = $agreement?->noticeDeadline();
@endphp

<div class="space-y-6">
    @if($agreement)
        <x-ui.card>
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.badge :tone="$status === App\Modules\Crm\Models\Agreement::ACTIVE ? 'success' : 'neutral'">{{ App\Modules\Crm\Models\Agreement::statusLabel($status) }}</x-ui.badge>
                @if($agreement->signed_at)
                    <x-ui.badge tone="accent">{{ __('Signerat :date', ['date' => $agreement->signed_at->setTimezone(config('app.timezone'))->format('Y-m-d')]) }}</x-ui.badge>
                @endif
                <a href="{{ route('crm.accounts.show', $agreement->account_id) }}" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ $agreement->account?->name }}</a>
                @if($agreement->recurringInvoice && $canSeeInvoices)
                    <a href="{{ route('invoicing.agreements.show', $agreement->recurring_invoice_id) }}" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Avtalsfakturering :number', ['number' => $agreement->recurringInvoice->number]) }}</a>
                @endif
            </div>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-600">{{ __('Säg upp senast') }}</dt><dd class="font-semibold tabular-nums">{{ $deadline?->toDateString() ?? __('Inget slutdatum') }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Nuvarande period slutar') }}</dt><dd class="font-semibold tabular-nums">{{ $agreement->end_date?->toDateString() ?? __('Tills vidare') }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Förlängs') }}</dt><dd class="font-semibold">{{ $agreement->renews() ? __('ja, :months månader i taget', ['months' => $agreement->renewal_months]) : ($agreement->terminated_on ? __('nej, uppsagt :date', ['date' => $agreement->terminated_on->toDateString()]) : __('nej')) }}</dd></div>
            </dl>

            @if($canManage && $status !== App\Modules\Crm\Models\Agreement::ENDED)
                <div class="mt-5 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-5" x-data="{ confirming: false }">
                    @if($agreement->terminated_on === null)
                        <x-ui.field for="terminate-on" :label="__('Uppsagt den')" error="on">
                            <input id="terminate-on" type="date" class="{{ $input }} mt-1" wire:model="terminateOn">
                        </x-ui.field>
                        <button type="button" class="min-h-11 rounded-lg px-4 text-sm font-semibold ring-1 ring-gray-300 hover:bg-gray-50" x-show="! confirming" x-on:click="confirming = true">{{ __('Markera som uppsagt') }}</button>
                        <span x-show="confirming" x-cloak class="flex flex-wrap items-center gap-3 text-sm">
                            <span>{{ __('Avtalet slutar när perioden är slut och förlängs inte.') }}</span>
                            <button type="button" class="font-semibold text-accent-700" wire:click="terminate" x-on:click="confirming = false">{{ __('Ja, markera som uppsagt') }}</button>
                            <button type="button" class="text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                        </span>
                    @else
                        <button type="button" class="min-h-11 text-sm text-gray-700 underline" wire:click="withdrawTermination">{{ __('Återta uppsägningen') }}</button>
                    @endif
                </div>
            @endif
        </x-ui.card>
    @endif

    <form wire:submit="save">
        <fieldset @disabled(! $canManage) class="space-y-6">
            <x-ui.card :title="__('Avtalet')" :description="$locked ? __('Avtalet är signerat. Villkoren går inte att ändra; ansvarig, anteckningar och avtalsfakturering går att ändra.') : null">
                <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.field for="agreement-title" :label="__('Rubrik')" error="title" class="sm:col-span-2">
                        <input id="agreement-title" type="text" class="{{ $input }} mt-1" wire:model="form.title" maxlength="255" @disabled($locked) required>
                    </x-ui.field>
                    <x-ui.field for="agreement-account" :label="__('Kund eller prospekt')" error="account_id" class="sm:col-span-2">
                        <select id="agreement-account" class="{{ $input }} mt-1" wire:model.live="form.account_id" @disabled($locked) required>
                            <option value="">{{ __('Välj…') }}</option>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="agreement-kind" :label="__('Typ')" error="kind">
                        <select id="agreement-kind" class="{{ $input }} mt-1" wire:model="form.kind" @disabled($locked)>
                            @foreach($kinds as $kind)
                                <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="agreement-start" :label="__('Startdatum')" error="start_date">
                        <input id="agreement-start" type="date" class="{{ $input }} mt-1" wire:model="form.start_date" @disabled($locked) required>
                    </x-ui.field>
                    <x-ui.field for="agreement-end" :label="__('Slutdatum (tomt: tills vidare)')" error="end_date">
                        <input id="agreement-end" type="date" class="{{ $input }} mt-1" wire:model="form.end_date" @disabled($locked)>
                    </x-ui.field>
                    <x-ui.field for="agreement-notice" :label="__('Uppsägningstid, månader')" error="notice_months">
                        <input id="agreement-notice" type="number" min="0" max="36" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.notice_months" @disabled($locked)>
                    </x-ui.field>
                    <div class="flex items-center sm:col-span-2">
                        <label class="flex items-start gap-3 text-sm">
                            <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model.live="form.auto_renew" @disabled($locked)>
                            <span>{{ __('Förlängs automatiskt om det inte sägs upp') }}</span>
                        </label>
                    </div>
                    @if($form['auto_renew'])
                        <x-ui.field for="agreement-renewal" :label="__('Förlängs med, månader')" error="renewal_months">
                            <input id="agreement-renewal" type="number" min="1" max="60" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.renewal_months" @disabled($locked)>
                        </x-ui.field>
                    @endif
                    <x-ui.field for="agreement-value" :label="__('Värde exkl. moms, kr')" error="value">
                        <input id="agreement-value" type="text" inputmode="decimal" class="{{ $input }} mt-1 tabular-nums" wire:model="form.value" @disabled($locked)>
                        <x-input-error for="value_ore" class="mt-1" />
                    </x-ui.field>
                    <x-ui.field for="agreement-period" :label="__('Per')" error="value_period">
                        <select id="agreement-period" class="{{ $input }} mt-1" wire:model="form.value_period" @disabled($locked)>
                            @foreach($periods as $period)
                                <option value="{{ $period->value }}">{{ $period->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="agreement-owner" :label="__('Ansvarig')" error="owner_id">
                        <select id="agreement-owner" class="{{ $input }} mt-1" wire:model="form.owner_id">
                            <option value="">{{ __('Ingen') }}</option>
                            @foreach($members as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    @if($canSeeInvoices)
                        <x-ui.field for="agreement-recurring" :label="__('Avtalsfakturering')" error="recurring_invoice_id">
                            <select id="agreement-recurring" class="{{ $input }} mt-1" wire:model="form.recurring_invoice_id">
                                <option value="">{{ $recurring->isEmpty() ? __('Ingen hos kunden') : __('Ingen') }}</option>
                                @foreach($recurring as $item)
                                    <option value="{{ $item->id }}">{{ $item->number }} {{ $item->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif
                    <x-ui.field for="agreement-body" :label="__('Avtalstext (frivilligt)')" error="body_text" class="sm:col-span-2 lg:col-span-4" :hint="__('Utan uppladdad PDF blir avtalet en PDF med uppgifterna ovan och den här texten när det skickas för signering.')">
                        <textarea id="agreement-body" rows="6" class="{{ $input }} mt-1" wire:model="form.body_text" maxlength="20000" @disabled($locked)></textarea>
                    </x-ui.field>
                    <x-ui.field for="agreement-notes" :label="__('Interna anteckningar')" error="notes" class="sm:col-span-2 lg:col-span-4">
                        <textarea id="agreement-notes" rows="3" class="{{ $input }} mt-1" wire:model="form.notes" maxlength="5000"></textarea>
                    </x-ui.field>
                </div>
                @if($canManage)
                    <div class="mt-6 flex flex-wrap items-center justify-end gap-3">
                        <a href="{{ route('crm.agreements') }}" class="text-sm text-gray-600 underline">{{ __('Tillbaka') }}</a>
                        <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    </div>
                @endif
            </x-ui.card>
        </fieldset>
    </form>

    @if($agreement)
        <x-ui.card :title="__('Avtalets PDF')">
            @if($agreement->document_path)
                <p class="mt-2 text-sm text-gray-700">{{ $agreement->document_name }} · <span class="break-all text-gray-600">{{ __('SHA-256: :hash', ['hash' => $agreement->document_sha256]) }}</span></p>
            @else
                <p class="mt-2 text-sm text-gray-600">{{ __('Ingen uppladdad PDF. När avtalet skickas för signering skapas en PDF av uppgifterna och avtalstexten.') }}</p>
            @endif
            @if($canManage && ! $locked)
                <form wire:submit="upload" class="mt-4 flex flex-wrap items-end gap-3">
                    <x-ui.field for="agreement-file" :label="__('Ladda upp avtalet som PDF (högst 10 MB)')" error="file">
                        <input id="agreement-file" type="file" accept="application/pdf" class="mt-1 block text-sm" wire:model="file">
                    </x-ui.field>
                    <x-button wire:loading.attr="disabled">{{ __('Ladda upp') }}</x-button>
                </form>
            @endif
        </x-ui.card>

        <livewire:signing.panel :agreement-id="$agreement->id" :key="'signing-agreement-'.$agreement->id" />
    @endif
</div>
