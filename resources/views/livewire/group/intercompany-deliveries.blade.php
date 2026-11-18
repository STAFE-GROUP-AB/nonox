@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<div class="space-y-6" data-intercompany-deliveries>
    <div data-receipt-settings>
    <x-ui.card :title="__('Ta emot koncernfakturor')"
        :description="__('Ett annat företag i samma koncern som också använder NONOX kan leverera sina fakturor direkt till företagets inkorg, men bara från de koncernföretag som står här. Fakturan kommer som en e-faktura och hanteras som en vanlig leverantörsfaktura.')">
        @if($settings->isEmpty())
            <x-ui.empty-state :title="__('Inga koncernföretag får leverera fakturor hit')">
                {{ __('Lägg till organisationsnumret för ett koncernföretag som ska få leverera sina fakturor till företagets inkorg. Inkorgen måste vara påslagen.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Koncernföretagets organisationsnummer') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                            <th class="py-2 pr-4"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($settings as $setting)
                            <tr wire:key="receipt-{{ $setting->id }}">
                                <td class="py-2 pr-4 tabular-nums">{{ $setting->sender_org_number }}</td>
                                <td class="py-2 pr-4">{{ $setting->enabled ? __('Tar emot') : __('Tar inte emot') }}</td>
                                <td class="py-2 pr-4 text-right">
                                    @if($canReceipt)
                                        @if($setting->enabled)
                                            <button type="button" class="text-sm text-gray-600 underline" wire:click="disableReceipt({{ $setting->id }})" wire:confirm="{{ __('Fakturor från :number levereras inte längre till inkorgen. Fakturor som redan har kommit ligger kvar.', ['number' => $setting->sender_org_number]) }}">{{ __('Sluta ta emot') }}</button>
                                        @else
                                            <button type="button" class="text-sm text-accent-600 underline" wire:click="enableReceipt('{{ $setting->sender_org_number }}')">{{ __('Ta emot igen') }}</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($canReceipt)
            <form wire:submit="enableReceipt" class="mt-6 flex flex-wrap items-end gap-3">
                <div>
                    <label for="receipt_org" class="block text-sm font-medium text-gray-700">{{ __('Koncernföretagets organisationsnummer') }}</label>
                    <input id="receipt_org" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="senderOrgNumber" placeholder="556123-4567">
                    <x-input-error for="sender_org_number" class="mt-1" />
                </div>
                <x-button wire:loading.attr="disabled">{{ __('Ta emot fakturor') }}</x-button>
            </form>

            @if($suggestions->isNotEmpty())
                <div class="mt-4 rounded-2xl bg-stone-50 p-4" data-receipt-suggestions>
                    <h3 class="text-sm font-semibold text-ink">{{ __('Förslag från leverantörerna') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('De här leverantörerna är markerade som koncernföretag.') }}</p>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach($suggestions as $suggestion)
                            <li wire:key="receipt-suggestion-{{ $suggestion->id }}">
                                <button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="enableReceipt('{{ $suggestion->counterparty_org_number }}')">
                                    {{ __('Ta emot från :name, :number', ['name' => $suggestion->supplier?->name, 'number' => $suggestion->counterparty_org_number]) }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @else
            <p class="mt-4 text-sm text-gray-500">{{ __('Bara företagets ägare eller administratör kan ändra vilka koncernföretag som får leverera fakturor hit.') }}</p>
        @endif
    </x-ui.card>
    </div>

    @if($deliveries !== null)
        <x-ui.card :title="__('Levererade koncernfakturor')"
            :description="__('Fakturor som företaget har levererat till ett annat koncernföretags inkorg. Ni ser bara om fakturan kom fram, inte hur mottagaren hanterar den.')">
            @if($deliveries->isEmpty())
                <x-ui.empty-state :title="__('Inga fakturor levererade ännu')">
                    {{ __('Öppna en utfärdad faktura till en kund som är markerad som koncernföretag och välj Leverera till koncernföretaget.') }}
                </x-ui.empty-state>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                                <th class="py-2 pr-4 font-medium">{{ __('Faktura') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Mottagare') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Tid') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Av') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($deliveries as $delivery)
                                <tr wire:key="sent-{{ $delivery->id }}">
                                    <td class="py-2 pr-4">
                                        @if($delivery->invoice)
                                            <a href="{{ route('invoicing.invoices.show', $delivery->invoice) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $delivery->invoice->title() }}</a>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $delivery->recipient_org_number }}</td>
                                    <td class="py-2 pr-4">{{ $delivery->status->label() }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $delivery->created_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                    <td class="py-2 pr-4">{{ $delivery->deliverer?->name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $deliveries->links() }}</div>
            @endif
        </x-ui.card>
    @endif

    @if($pairs !== null)
        <x-ui.card :title="__('Motsvarande utkast')"
            :description="__('Mellanhavanden utan faktura som bokförs i båda företagen, till exempel lån, ränta och koncernbidrag. Skapa ett motsvarande utkast från en bokförd verifikation.')">
            @if($pairs->isEmpty())
                <x-ui.empty-state :title="__('Inga motsvarande utkast ännu')">
                    {{ __('Öppna en bokförd verifikation och välj Skapa motsvarande utkast. Det kräver att du får skapa verifikationer i båda företagen.') }}
                </x-ui.empty-state>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                                <th class="py-2 pr-4 font-medium">{{ __('Verifikation') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Mellanhavande') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Motpart') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Motpartens verifikation') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($pairs as $pair)
                                <tr wire:key="pair-row-{{ $pair->id }}">
                                    <td class="py-2 pr-4">
                                        @if($pair->voucher)
                                            <a href="{{ route('accounting.vouchers.show', $pair->voucher) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $pair->voucher->isPosted() ? $pair->voucher->reference() : __('Utkast') }}</a>
                                            <span class="text-gray-600">{{ $pair->voucher->description }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">{{ $pair->kind->label() }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $pair->counterparty_org_number }}</td>
                                    <td class="py-2 pr-4">{{ $pair->counterparty_voucher_ref ?? '–' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $pairs->links() }}</div>
            @endif
        </x-ui.card>
    @endif
</div>
