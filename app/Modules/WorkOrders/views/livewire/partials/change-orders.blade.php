<x-ui.card :title="__('ÄTA')" :description="__('Ändrings- och tilläggsarbeten som kunden godkänner med pris innan arbetet görs.')">
    @if($canReport && $workOrder->status->isOpen() && $editingChange === null)
        <x-slot:cta>
            <x-secondary-button type="button" wire:click="newChange">{{ __('Nytt ÄTA') }}</x-secondary-button>
        </x-slot:cta>
    @endif

    @if($editingChange !== null)
        <form wire:submit="saveChange" class="mb-6 grid gap-4 sm:grid-cols-6">
            <x-ui.field for="change-title" :label="__('Vad gäller ändringen?')" error="change.title" class="sm:col-span-3"><input id="change-title" wire:model="change.title" maxlength="255" class="{{ $input }}" required></x-ui.field>
            <x-ui.field for="change-basis" :label="__('Grund')" error="change.basis" class="sm:col-span-3">
                <select id="change-basis" wire:model="change.basis" class="{{ $input }}">
                    @foreach($bases as $basis)<option value="{{ $basis->value }}">{{ $basis->label() }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="change-description" :label="__('Vad ska göras')" error="change.description" class="sm:col-span-6"><textarea id="change-description" wire:model="change.description" rows="3" class="{{ $input }}" required></textarea></x-ui.field>
            @if($canManage)
                <x-ui.field for="change-price-form" :label="__('Prisform')" error="change.price_form" class="sm:col-span-2">
                    <select id="change-price-form" wire:model.live="change.price_form" class="{{ $input }}">
                        @foreach($priceForms as $priceForm)<option value="{{ $priceForm->value }}">{{ $priceForm->label() }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="change-labour" :label="__('Arbete exklusive moms')" error="change.labour" class="sm:col-span-2"><input id="change-labour" wire:model="change.labour" inputmode="decimal" class="{{ $input }} text-right"></x-ui.field>
                <x-ui.field for="change-material" :label="__('Material exklusive moms')" error="change.material" class="sm:col-span-2"><input id="change-material" wire:model="change.material" inputmode="decimal" class="{{ $input }} text-right"></x-ui.field>
                <x-ui.field for="change-other" :label="__('Övrigt exklusive moms')" error="change.other" class="sm:col-span-2"><input id="change-other" wire:model="change.other" inputmode="decimal" class="{{ $input }} text-right"></x-ui.field>
                @if(($change['price_form'] ?? '') === 'ESTIMATE')
                    <x-ui.field for="change-limit" :label="__('Övre gräns inklusive moms')" error="change.limit" class="sm:col-span-2"><input id="change-limit" wire:model="change.limit" inputmode="decimal" class="{{ $input }} text-right"></x-ui.field>
                @endif
                <x-ui.field for="change-time" :label="__('Påverkan på tiden')" error="change.time_effect" class="sm:col-span-2"><input id="change-time" wire:model="change.time_effect" maxlength="255" placeholder="{{ __('Till exempel en dag till') }}" class="{{ $input }}"></x-ui.field>
                <x-ui.field for="change-payment" :label="__('Betalning')" error="change.payment_terms" class="sm:col-span-2"><input id="change-payment" wire:model="change.payment_terms" maxlength="255" placeholder="{{ __('Med nästa faktura') }}" class="{{ $input }}"></x-ui.field>
                @if($workOrder->tax_reduction)
                    <label class="flex items-center gap-2 text-sm sm:col-span-6"><input type="checkbox" wire:model="change.tax_reduction_applies" class="rounded border-gray-300 text-accent-600"> {{ __(':type på arbetet i detta ÄTA (av till exempel när försäkringen betalar)', ['type' => App\Domain\Invoicing\TaxReduction::label($workOrder->tax_reduction)]) }}</label>
                @endif
            @else
                <p class="text-sm text-gray-500 sm:col-span-6">{{ __('Kontoret sätter priset och skickar ÄTA:t till kunden.') }}</p>
            @endif
            <div class="flex flex-wrap gap-3 sm:col-span-6">
                <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara ÄTA') }}</x-button>
                <x-secondary-button type="button" wire:click="cancelChange">{{ __('Avbryt') }}</x-secondary-button>
            </div>
        </form>
    @endif

    @if($changeOrders->isEmpty())
        <x-ui.empty-state :title="__('Inga ÄTA')">{{ __('När kunden vill ändra något eller ett tilläggsarbete behövs, skapa ett ÄTA och låt kunden godkänna priset innan arbetet görs.') }}</x-ui.empty-state>
    @else
        <ul class="divide-y divide-stone-200">
            @foreach($changeOrders as $changeOrder)
                <li class="py-3" wire:key="change-{{ $changeOrder->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="font-medium">{{ $changeOrder->label() }}: {{ $changeOrder->title }}</p>
                            <p class="text-sm text-gray-500">{{ $changeOrder->status->label() }} · {{ $kr($changeOrder->netOre()) }} {{ __('exkl. moms') }}@if($changeOrder->status === App\Modules\WorkOrders\Domain\ChangeOrderStatus::ApprovedOrally) · {{ __('muntligt, svagare bevis') }}@endif @if($changeOrder->contact_attempts_count) · {{ __('försök att nå kunden: :count', ['count' => $changeOrder->contact_attempts_count]) }}@endif</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('workOrders.changeOrderPdf', [$workOrder->id, $changeOrder->id]) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('PDF') }}</a>
                            @if($changeOrder->status === App\Modules\WorkOrders\Domain\ChangeOrderStatus::Draft && $canManage)
                                <button type="button" wire:click="editChange({{ $changeOrder->id }})" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Ändra') }}</button>
                            @endif
                            @if(! $changeOrder->status->isDecided() && $canSend)
                                <button type="button" wire:click="sendForSigning({{ $changeOrder->id }})" wire:confirm="{{ $changeOrder->status === App\Modules\WorkOrders\Domain\ChangeOrderStatus::Draft ? __('Skicka ÄTA:t till kunden för signering? Innehållet och priset låses när det skickas.') : __('Skicka en ny signeringslänk? Den gamla slutar gälla.') }}" class="text-sm font-medium text-accent-700 hover:underline">{{ $changeOrder->status === App\Modules\WorkOrders\Domain\ChangeOrderStatus::Draft ? __('Skicka för signering') : __('Skicka länken igen') }}</button>
                                <button type="button" wire:click="openDecision({{ $changeOrder->id }}, 'oral')" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Godkänt muntligt') }}</button>
                                <button type="button" wire:click="openDecision({{ $changeOrder->id }}, 'attempt')" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Försök att nå kunden') }}</button>
                                <button type="button" wire:click="openDecision({{ $changeOrder->id }}, 'performed')" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Utfört utan besked') }}</button>
                                <button type="button" wire:click="openDecision({{ $changeOrder->id }}, 'declined')" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Kunden avböjde') }}</button>
                                <button type="button" wire:click="withdrawChange({{ $changeOrder->id }})" wire:confirm="{{ __('Återkalla ÄTA:t? Det kan inte ändras efteråt.') }}" class="text-sm font-medium text-red-700 hover:underline">{{ __('Återkalla') }}</button>
                            @endif
                        </div>
                    </div>

                    @if($decidingChange === $changeOrder->id)
                        <form wire:submit="submitDecision" class="mt-3 grid gap-3 rounded-lg bg-stone-50 p-3 sm:grid-cols-6">
                            @if(in_array($decision, ['oral', 'declined'], true))
                                <x-ui.field for="decide-name" :label="__('Vem svarade?')" error="decide.name" class="sm:col-span-3"><input id="decide-name" wire:model="decide.name" class="{{ $input }}"></x-ui.field>
                            @endif
                            @if($decision === 'oral')
                                <x-ui.field for="decide-title" :label="__('Titel (företag)')" error="decide.title" class="sm:col-span-3"><input id="decide-title" wire:model="decide.title" class="{{ $input }}"></x-ui.field>
                            @endif
                            @if(in_array($decision, ['oral', 'attempt'], true))
                                <x-ui.field for="decide-channel" :label="__('Hur')" error="decide.channel" class="sm:col-span-3">
                                    <select id="decide-channel" wire:model="decide.channel" class="{{ $input }}">
                                        @foreach($channels as $channel)<option value="{{ $channel->value }}">{{ $channel->label() }}</option>@endforeach
                                    </select>
                                </x-ui.field>
                                <x-ui.field for="decide-at" :label="__('När')" error="decide.at" class="sm:col-span-3"><input id="decide-at" type="datetime-local" wire:model="decide.at" class="{{ $input }}"></x-ui.field>
                            @endif
                            @if($decision === 'attempt')
                                <x-ui.field for="decide-note" :label="__('Anteckning')" error="decide.note" class="sm:col-span-6"><input id="decide-note" wire:model="decide.note" maxlength="255" class="{{ $input }}"></x-ui.field>
                            @endif
                            @if($decision === 'performed')
                                <x-ui.field for="decide-reason" :label="__('Skäl enligt 8 § konsumenttjänstlagen')" error="decide.reason" class="sm:col-span-6">
                                    <select id="decide-reason" wire:model="decide.reason" class="{{ $input }}">
                                        <option value="">{{ __('Välj skäl') }}</option>
                                        @foreach($reasons as $reason)<option value="{{ $reason->value }}">{{ $reason->label() }}</option>@endforeach
                                    </select>
                                </x-ui.field>
                                <x-ui.field for="decide-justification" :label="__('Motivering')" error="decide.justification" class="sm:col-span-6"><textarea id="decide-justification" wire:model="decide.justification" rows="2" class="{{ $input }}"></textarea></x-ui.field>
                                <p class="text-xs text-gray-500 sm:col-span-6">{{ __('NONOX bedömer inte om priset är lågt nog. Registrera först minst ett försök att nå kunden. Kunden får ÄTA:t som PDF via e-post.') }}</p>
                            @endif
                            <div class="flex flex-wrap gap-3 sm:col-span-6">
                                <x-button type="submit" wire:loading.attr="disabled" wire:confirm="{{ $decision === 'attempt' ? __('Registrera försöket?') : __('Registrera beslutet? Ett beslutat ÄTA kan aldrig ändras.') }}">{{ __('Registrera') }}</x-button>
                                <x-secondary-button type="button" wire:click="cancelChange">{{ __('Avbryt') }}</x-secondary-button>
                            </div>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
