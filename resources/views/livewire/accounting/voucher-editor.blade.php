@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $cell = 'w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-md text-sm max-md:min-h-11';
    // Below md a row is a card with labelled fields; from md up it is the keyboard-first grid.
    $field = 'block md:table-cell md:py-1 md:pr-2 md:align-top';
    $label = 'mb-1 block text-xs font-medium text-gray-500 md:sr-only';
    $balanceHint = __('Kontots saldo efter raden, på kontots normala sida: tillgångar och kostnader som debet, eget kapital, skulder och intäkter som kredit. Bara bokförda verifikationer till och med datumet räknas.');
@endphp

<div
    x-data="voucherEditor($wire.entangle('rows'), @js($canPost), $wire.entangle('balances'), @js($accountSigns))"
    x-on:keydown.window="shortcuts($event)"
    data-back-url="{{ route('accounting.vouchers.index') }}"
    class="flex flex-col gap-6"
>
    <datalist id="account-options">
        @foreach($accounts as $account)
            <option value="{{ $account->number }} {{ $account->name }}"></option>
        @endforeach
    </datalist>
    <datalist id="cost-center-options">
        @foreach($costCenters as $costCenter)
            <option value="{{ $costCenter->label() }}"></option>
        @endforeach
    </datalist>
    <datalist id="project-options">
        @foreach($projects as $project)
            <option value="{{ $project->label() }}"></option>
        @endforeach
    </datalist>

    @if($errors->has('voucher') || $errors->has('rows'))
        <div class="rounded-xl border border-accent-200 bg-accent-50 px-5 py-4 text-sm text-accent-800" role="alert">
            @foreach(array_merge($errors->get('voucher'), $errors->get('rows')) as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif

    <x-ui.card>
        <div class="grid gap-5 sm:grid-cols-[10rem_1fr_9rem]">
            <x-ui.field for="voucher_date" :label="__('Datum')" error="voucher_date">
                <input id="voucher_date" type="date" class="{{ $input }} mt-1" wire:model.live.blur="voucherDate" data-grid x-on:keydown.enter.prevent="focusNext($event)">
            </x-ui.field>
            <x-ui.field for="voucher_description" :label="__('Vad gäller det?')" error="description">
                <input id="voucher_description" type="text" class="{{ $input }} mt-1" wire:model="description" placeholder="{{ __('Till exempel: Kontorsmaterial från Clas Ohlson') }}" maxlength="255" data-grid x-on:keydown.enter.prevent="focusNext($event)" autofocus>
            </x-ui.field>
            <x-ui.field for="voucher_series" :label="__('Serie')" error="series">
                <select id="voucher_series" class="{{ $input }} mt-1" wire:model="series">
                    @foreach($seriesOptions as $option)
                        <option value="{{ $option->code }}">{{ $option->code }} – {{ __($option->name) }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        </div>
    </x-ui.card>

    <details class="rounded-xl border border-gray-200 bg-white px-6 py-4 sm:px-8" @if($errors->has('template') || $errors->has('templateAmount')) open @endif>
        <summary class="cursor-pointer font-semibold text-gray-900">{{ __('Snabbare: använd en mall') }}</summary>
        <p class="mt-2 text-sm text-gray-600">{{ __('Välj vad det gäller och skriv beloppet du betalade eller fick, inklusive moms. Raderna och momsen fylls i åt dig.') }}</p>
        <form wire:submit="applyTemplate" class="mt-4 grid gap-4 sm:grid-cols-[1fr_10rem_auto] sm:items-start">
            <x-ui.field for="template" :label="__('Mall')" error="template">
                <select id="template" class="{{ $input }} mt-1" wire:model="template">
                    <option value="">{{ __('Välj…') }}</option>
                    @foreach($templates as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="template_amount" :label="__('Belopp inkl. moms')" error="templateAmount">
                <input id="template_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="templateAmount" placeholder="1 250,00">
            </x-ui.field>
            <div class="sm:pt-6">
                <button type="submit" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50" wire:loading.attr="disabled" wire:target="applyTemplate">{{ __('Fyll i rader') }}</button>
            </div>
        </form>
    </details>

    <x-ui.card :title="__('Rader')" :description="__('Skriv kontonummer eller kontonamn och välj i listan. Tab eller Enter tar dig till nästa fält, och en ny rad dyker upp av sig själv.')">
        <div class="md:overflow-x-auto">
            <table class="block w-full text-sm md:table">
                <thead class="hidden md:table-header-group">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="pb-2 pr-2 font-medium">{{ __('Konto') }}</th>
                        @if($showCostCenters)
                            <th scope="col" class="pb-2 pr-2 font-medium">{{ __('Kostnadsställe') }}</th>
                        @endif
                        @if($showProjects)
                            <th scope="col" class="pb-2 pr-2 font-medium">{{ __('Projekt') }}</th>
                        @endif
                        <th scope="col" class="pb-2 pr-2 font-medium">{{ __('Radtext (frivillig)') }}</th>
                        <th scope="col" class="pb-2 pr-2 font-medium text-right w-36">{{ __('Debet') }}</th>
                        <th scope="col" class="pb-2 pr-2 font-medium text-right w-36">{{ __('Kredit') }}</th>
                        <th scope="col" class="pb-2 pr-2 font-medium text-right w-36" title="{{ $balanceHint }}">{{ __('Kontosaldo') }}</th>
                        <th scope="col" class="pb-2 w-8"><span class="sr-only">{{ __('Ta bort') }}</span></th>
                    </tr>
                </thead>
                <tbody class="flex flex-col gap-3 md:table-row-group" data-row-cards>
                    <template x-for="(row, index) in rows" :key="index">
                        <tr class="relative grid grid-cols-2 gap-x-3 gap-y-3 rounded-2xl p-4 ring-1 ring-gray-200 md:table-row md:p-0 md:ring-0">
                            <td class="{{ $field }} col-span-2 pr-10 md:min-w-[16rem]">
                                <label :for="'row-account-' + index" class="{{ $label }}" x-text="'{{ __('Konto, rad') }} ' + (index + 1)"></label>
                                <input :id="'row-account-' + index" type="text" list="account-options" class="{{ $cell }}" x-model="row.account" data-grid x-on:keydown.enter.prevent="focusNext($event)" autocomplete="off" :aria-describedby="'row-balance-now-' + index">
                                <p :id="'row-balance-now-' + index" class="mt-1 text-xs text-gray-500" x-show="balanceBefore(row) !== null" x-cloak>
                                    {{ __('Saldo nu') }} <span class="tabular-nums" :class="balanceBefore(row) < 0 && 'text-accent-700'" x-text="balanceBefore(row) === null ? '' : format(balanceBefore(row))"></span>
                                </p>
                            </td>
                            @if($showCostCenters)
                                <td class="{{ $field }} md:min-w-40">
                                    <label :for="'row-cost-center-' + index" class="{{ $label }}" x-text="'{{ __('Kostnadsställe, rad') }} ' + (index + 1)"></label>
                                    <input :id="'row-cost-center-' + index" type="text" list="cost-center-options" class="{{ $cell }}" x-model="row.cost_center" data-grid x-on:keydown.enter.prevent="focusNext($event)" autocomplete="off">
                                </td>
                            @endif
                            @if($showProjects)
                                <td class="{{ $field }} md:min-w-40">
                                    <label :for="'row-project-' + index" class="{{ $label }}" x-text="'{{ __('Projekt, rad') }} ' + (index + 1)"></label>
                                    <input :id="'row-project-' + index" type="text" list="project-options" class="{{ $cell }}" x-model="row.project" data-grid x-on:keydown.enter.prevent="focusNext($event)" autocomplete="off">
                                </td>
                            @endif
                            <td class="{{ $field }} col-span-2">
                                <label :for="'row-description-' + index" class="{{ $label }}" x-text="'{{ __('Radtext, rad') }} ' + (index + 1)"></label>
                                <input :id="'row-description-' + index" type="text" class="{{ $cell }}" x-model="row.description" maxlength="255" data-grid x-on:keydown.enter.prevent="focusNext($event)" autocomplete="off">
                            </td>
                            <td class="{{ $field }}">
                                <label :for="'row-debit-' + index" class="{{ $label }}" x-text="'{{ __('Debet, rad') }} ' + (index + 1)"></label>
                                <input :id="'row-debit-' + index" type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" :class="invalid(row.debit) && 'border-accent-500'" x-model="row.debit" data-grid x-on:keydown.enter.prevent="focusNext($event)" autocomplete="off">
                            </td>
                            <td class="{{ $field }}">
                                <label :for="'row-credit-' + index" class="{{ $label }}" x-text="'{{ __('Kredit, rad') }} ' + (index + 1)"></label>
                                <input :id="'row-credit-' + index" type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" :class="invalid(row.credit) && 'border-accent-500'" x-model="row.credit" data-grid x-on:keydown.enter.prevent="focusNext($event)" autocomplete="off">
                            </td>
                            {{-- Read only, so outside the Tab/Enter order: the balance after this row would be posted. --}}
                            <td class="col-span-2 flex items-baseline justify-between md:table-cell md:py-1 md:pt-3 md:pr-2 md:align-top md:text-right" :class="balancesAfter[index] === null && 'max-md:hidden'" data-account-balance>
                                <span class="{{ $label }}">{{ __('Kontosaldo efter raden') }}</span>
                                <span class="text-sm tabular-nums text-gray-700" :class="balancesAfter[index] < 0 && 'text-accent-700'" x-text="balancesAfter[index] === null ? '' : format(balancesAfter[index])"></span>
                            </td>
                            <td class="absolute top-2 right-2 md:static md:table-cell md:py-1 md:align-top md:text-center">
                                <button type="button" class="inline-flex size-11 items-center justify-center rounded-full text-xl text-gray-400 hover:text-accent-600 md:mt-1.5 md:inline md:size-auto md:text-sm" x-on:click="removeRow(index)" x-show="rows.length > 1" :aria-label="'{{ __('Ta bort rad') }} ' + (index + 1)">×</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot class="mt-3 block md:table-footer-group">
                    <tr class="grid grid-cols-[1fr_auto_auto] gap-x-4 border-t border-gray-200 font-semibold md:table-row">
                        <td class="pt-3 md:table-cell md:pr-2" colspan="{{ 2 + ($showCostCenters ? 1 : 0) + ($showProjects ? 1 : 0) }}">{{ __('Summa') }}</td>
                        <td class="pt-3 text-right tabular-nums md:table-cell md:pr-2"><span class="mr-1 text-xs font-medium text-gray-500 md:hidden">{{ __('Debet') }}</span><span x-text="format(debit)"></span></td>
                        <td class="pt-3 text-right tabular-nums md:table-cell md:pr-2"><span class="mr-1 text-xs font-medium text-gray-500 md:hidden">{{ __('Kredit') }}</span><span x-text="format(credit)"></span></td>
                        <td class="hidden md:table-cell" colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @foreach($errors->getMessages() as $key => $messages)
            @if(str_starts_with($key, 'rows.'))
                @foreach($messages as $message)
                    <p class="mt-2 text-sm text-accent-700">{{ $message }}</p>
                @endforeach
            @endif
        @endforeach

        @if($assetSettings)
            @include('partials.asset-proposals', ['kind' => 'voucher', 'blocked' => 'false', 'fallbackName' => '$wire.description'])
        @endif

        <div class="mt-5 rounded-lg px-4 py-3 text-sm font-medium" role="status" aria-live="polite" data-balance-status
             :class="balanced ? 'bg-green-50 text-green-800' : 'bg-gray-100 text-gray-700'">
            <span x-show="hasInvalidAmount">{{ __('Ett belopp går inte att läsa. Skriv till exempel 1 234,50.') }}</span>
            <span x-show="! hasInvalidAmount && missingAccount">{{ __('Välj ett konto på varje rad som har ett belopp.') }}</span>
            <span x-show="! hasInvalidAmount && balanced">✓ {{ __('Debet och kredit är lika. Verifikationen kan bokföras.') }}</span>
            <span x-show="! hasInvalidAmount && ! missingAccount && ! balanced && filledRows < 2">{{ __('Fyll i minst två rader.') }}</span>
            <span x-show="! hasInvalidAmount && ! missingAccount && ! balanced && filledRows >= 2">
                {{ __('Det skiljer') }} <span class="tabular-nums" x-text="format(Math.abs(difference))"></span> {{ __('kr mellan debet och kredit.') }}
            </span>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Underlag')" :description="__('Kvitto eller faktura som visar vad som hänt. PDF eller bild, högst 10 MB. Underlag sparas för alltid och kan inte tas bort när verifikationen är bokförd.')">
        <div
            x-data="{ over: false }"
            x-on:dragover.prevent="over = true"
            x-on:dragleave.prevent="over = false"
            x-on:drop.prevent="over = false; $wire.uploadMultiple('uploads', $event.dataTransfer.files)"
            :class="over ? 'border-accent-500 bg-accent-50' : 'border-gray-300'"
            class="rounded-xl border-2 border-dashed px-6 py-8 text-center"
        >
            <p class="text-sm text-gray-700">{{ __('Dra hit kvitton och fakturor, eller') }}
                <label for="uploads" class="cursor-pointer font-semibold text-accent-600 hover:text-accent-700">{{ __('välj filer') }}</label>
            </p>
            <input id="uploads" type="file" class="sr-only" wire:model="uploads" multiple accept="application/pdf,image/jpeg,image/png,image/webp">
            {{-- On a phone the camera opens directly; the photo is checked and stored like any other underlag. --}}
            <label for="uploads-camera" class="mt-3 inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 max-sm:min-h-11">{{ __('Fota underlaget') }}</label>
            <input id="uploads-camera" type="file" class="sr-only" accept="image/*" capture="environment" x-on:change="$event.target.files.length && $wire.uploadMultiple('uploads', $event.target.files)">
            <p class="mt-1 text-xs text-gray-500" wire:loading wire:target="uploads">{{ __('Laddar upp…') }}</p>
        </div>

        @error('uploads') <p class="mt-2 text-sm text-accent-700">{{ $message }}</p> @enderror
        @include('ai::partials.analyze-panel', ['canAnalyze' => $aiAvailable && ($uploads !== [] || $attachments->isNotEmpty())])

        @if($attachments->isNotEmpty() || $uploads !== [])
            <ul class="mt-4 divide-y divide-gray-100 text-sm">
                @foreach($attachments as $attachment)
                    <li class="flex items-center justify-between gap-3 py-2" wire:key="attachment-{{ $attachment->id }}">
                        <a href="{{ route('accounting.vouchers.attachment', [$voucherId, $attachment->id]) }}" target="_blank" class="font-medium text-gray-900 hover:text-accent-700">{{ $attachment->original_name }}</a>
                        <span class="flex items-center gap-3 text-gray-500">
                            {{ $attachment->humanSize() }}
                            <button type="button" class="text-gray-600 underline" wire:click="removeAttachment({{ $attachment->id }})">{{ __('Ta bort') }}</button>
                        </span>
                    </li>
                @endforeach
                @foreach($uploads as $index => $upload)
                    <li class="flex items-center justify-between gap-3 py-2" wire:key="upload-{{ $index }}">
                        <span class="font-medium text-gray-900">{{ $upload->getClientOriginalName() }}</span>
                        <span class="flex items-center gap-3 text-gray-500">
                            {{ __('Sparas när du sparar eller bokför') }}
                            <button type="button" class="text-gray-600 underline" wire:click="removeUpload({{ $index }})">{{ __('Ta bort') }}</button>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <div class="flex flex-wrap items-center justify-between gap-4">
        <button type="button" class="text-sm text-gray-600 underline" x-on:click="showHelp = ! showHelp">{{ __('Kortkommandon') }} (?)</button>

        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('accounting.vouchers.index') }}" class="text-sm text-gray-600 underline">{{ __('Avbryt') }}</a>
            <button type="button" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50" wire:click="saveDraft" wire:loading.attr="disabled">
                {{ __('Spara som utkast') }}
            </button>
            @if($canPost)
                <x-button type="button" x-on:click="requestPost()" x-bind:disabled="! balanced">
                    {{ __('Bokför') }}
                </x-button>
            @endif
        </div>
    </div>

    <div x-show="showHelp" x-cloak class="rounded-xl border border-gray-200 bg-white p-5 text-sm text-gray-700">
        <p class="font-semibold text-gray-900">{{ __('Kortkommandon') }}</p>
        <dl class="mt-3 grid gap-2 sm:grid-cols-2">
            <div><dt class="inline font-mono">Tab / Enter</dt> <dd class="inline">– {{ __('nästa fält') }}</dd></div>
            <div><dt class="inline font-mono">⌘ / Ctrl + Enter</dt> <dd class="inline">– {{ __('bokför') }}</dd></div>
            <div><dt class="inline font-mono">Esc</dt> <dd class="inline">– {{ __('tillbaka till listan') }}</dd></div>
            <div><dt class="inline font-mono">?</dt> <dd class="inline">– {{ __('visa eller dölj kortkommandon') }}</dd></div>
        </dl>
    </div>

    {{-- Posting cannot be undone, so it is confirmed in plain Swedish (AGENTS.md §11.3). --}}
    <div x-show="confirmingPost" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" role="dialog" aria-modal="true" aria-labelledby="confirm-post-title">
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" x-on:click.outside="confirmingPost = false">
            <h2 id="confirm-post-title" class="text-lg font-semibold text-gray-900">{{ __('Bokföra verifikationen?') }}</h2>
            <p class="mt-2 text-sm text-gray-600">{{ __('När den är bokförd får den ett nummer och kan aldrig ändras eller tas bort. Blir något fel rättar du det med en rättelseverifikation.') }}</p>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" class="text-sm text-gray-600 underline" x-on:click="confirmingPost = false">{{ __('Avbryt') }}</button>
                <x-button type="button" wire:click="post" x-on:click="confirmingPost = false" wire:loading.attr="disabled">{{ __('Ja, bokför') }}</x-button>
            </div>
        </div>
    </div>

    <div x-show="confirmingLeave" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" role="dialog" aria-modal="true" aria-labelledby="confirm-leave-title">
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" x-on:click.outside="confirmingLeave = false">
            <h2 id="confirm-leave-title" class="text-lg font-semibold text-gray-900">{{ __('Lämna utan att spara?') }}</h2>
            <p class="mt-2 text-sm text-gray-600">{{ __('Det du har skrivit sparas inte. Vill du behålla det, välj Spara som utkast.') }}</p>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" class="text-sm text-gray-600 underline" x-on:click="confirmingLeave = false">{{ __('Stanna kvar') }}</button>
                <button type="button" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg bg-gray-800 text-sm font-semibold text-white" x-on:click="leave()">{{ __('Lämna') }}</button>
            </div>
        </div>
    </div>
</div>
