@php
    use App\Modules\Inventory\Domain\PurchaseRequisitionStatus;
    use App\Modules\Inventory\Domain\Quantity;
    use App\Support\Money\Money;

    $button = 'inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700';
    $secondary = 'inline-flex items-center rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-stone-50';
    $kr = fn (int $ore): string => Money::ofOre($ore)->format();
    $status = $requisition->status;
    $rejectedByDefault = $status === PurchaseRequisitionStatus::Rejected && ! ($approval && $approval->awaitingResubmission);
@endphp
<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-600">{{ __('Skriven av') }}</dt><dd class="font-semibold">{{ $requisition->requester?->name }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Status') }}</dt><dd><x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge></dd></div>
                <div><dt class="text-gray-600">{{ __('Behövs senast') }}</dt><dd class="tabular-nums">{{ $requisition->needed_by?->toDateString() ?? '–' }}</dd></div>
                <div class="sm:col-span-3"><dt class="text-gray-600">{{ __('Skäl') }}</dt><dd>{{ $requisition->reason ?? '–' }}</dd></div>
                @if($orders->isNotEmpty())
                    <div class="sm:col-span-3"><dt class="text-gray-600">{{ __('Beställd på') }}</dt><dd>
                        @foreach($orders as $orderId => $number)
                            <a href="{{ route('inventory.purchaseOrders.show', $orderId) }}" class="font-semibold text-accent-700 underline">{{ __('Inköpsorder :number', ['number' => $number]) }}</a>@if(! $loop->last), @endif
                        @endforeach
                    </dd></div>
                @endif
            </dl>
            <div class="flex flex-wrap gap-3">
                @if($mayHandle && $status->isEditable())
                    <a href="{{ route('inventory.requisitions.edit', $requisition->id) }}" class="{{ $secondary }}">{{ __('Ändra') }}</a>
                @endif
                @if($mayHandle && $status === PurchaseRequisitionStatus::Draft)
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" class="{{ $button }}">{{ __('Skicka till attest') }}</button>
                @endif
                @if($mayHandle && $status === PurchaseRequisitionStatus::Submitted)
                    <button type="button" wire:click="takeBack" wire:confirm="{{ __('Ta tillbaka anmodan från attest? Den blir ett utkast som du kan ändra och skicka igen. Ingen attestant kan då godkänna den som den var.') }}" class="{{ $secondary }}">{{ __('Ta tillbaka') }}</button>
                @endif
                @if($canConvert && $status === PurchaseRequisitionStatus::Approved)
                    <a href="{{ route('inventory.requisitions.convert') }}" class="{{ $button }}">{{ __('Beställ') }}</a>
                @endif
                @if($mayHandle && $status->isOpen() && $requisition->rows->whereNotNull('purchase_order_row_id')->isEmpty())
                    <button type="button" wire:click="cancelRequisition" wire:confirm="{{ __('Makulera inköpsanmodan? Numret finns kvar men den gäller inte längre.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Makulera') }}</button>
                @endif
            </div>
        </div>

        @if($status === PurchaseRequisitionStatus::Submitted && ! $approval)
            <p class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">{{ __('Anmodan väntar på att någon med rätt att skicka inköpsorder attesterar den.') }}</p>
        @elseif($status === PurchaseRequisitionStatus::Approved)
            <p class="mt-4 rounded-xl border border-green-300 bg-green-50 p-3 text-sm text-green-900">{{ __('Attesterad :date. En inköpare kan nu lägga raderna på en inköpsorder.', ['date' => $requisition->approved_at?->toDateString()]) }}</p>
        @endif
        @if($blockedBySegregation)
            <p class="mt-3 text-sm text-gray-700">{{ __('Någon annan än den som skrev eller ändrade anmodan måste attestera den. Är du ensam i företaget kan ägaren stänga av kravet under Inköp → Leverantörsfakturor, under Attest.') }}</p>
        @endif
        <x-input-error for="requisition" class="mt-3" />
    </x-ui.card>

    @if($rejectedByDefault)
        <div class="rounded-2xl bg-accent-50 p-4 text-sm text-accent-900" role="status">
            <p class="font-medium">{{ __('Avvisad.') }}</p>
            @if($requisition->reject_reason)
                <p class="mt-1">{{ __('Skäl: :reason', ['reason' => $requisition->reject_reason]) }}</p>
            @endif
            @if($mayHandle)
                <p class="mt-2">{{ __('Ändra anmodan och skicka den igen, eller skicka den till attest utan ändring.') }}</p>
                <button type="button" class="mt-2 font-medium text-accent-700 underline" wire:click="resubmit" wire:loading.attr="disabled">{{ __('Skicka till attest igen') }}</button>
            @endif
        </div>
        <x-input-error for="approve" class="mt-2" />
    @endif

    @if($approval)
        @include('livewire.approval.partials.approval-flow', ['approval' => $approval, 'memberNames' => $memberNames, 'canResubmit' => $canResubmitFlow])
    @endif

    @if($canApprove || $canReject)
        <x-ui.card :title="__('Attestera')">
            @if($canApprove)
                <p class="text-sm text-gray-700">{{ __('Kontrollera raderna, beloppet och skälet. När anmodan är attesterad kan en inköpare lägga den på en inköpsorder.') }}</p>
                @if($approval?->eligibility->onBehalfOf)
                    <p class="mt-2 text-sm text-gray-700">{{ __('Du godkänner på uppdrag av :name.', ['name' => $memberNames[$approval->eligibility->onBehalfOf] ?? __('tidigare medlem')]) }}</p>
                @endif
            @endif
            <div class="mt-3 flex flex-wrap items-start gap-3">
                @if($canApprove)
                    <x-button type="button" wire:click="approve" wire:loading.attr="disabled">{{ $approval && $approval->stepCount() > 1 ? __('Godkänn steg :step av :steps', ['step' => $approval->currentStep(), 'steps' => $approval->stepCount()]) : __('Attestera anmodan') }}</x-button>
                @endif
                @if($canReject)
                    <span x-data="{ rejecting: false }" class="flex flex-col gap-2">
                        <button type="button" class="text-sm text-gray-600 underline" x-show="! rejecting" x-on:click="rejecting = true">{{ __('Avvisa') }}</button>
                        <span x-show="rejecting" x-cloak class="flex flex-col gap-2">
                            <label for="pr_reject_reason" class="text-sm font-medium text-gray-700">{{ __('Skäl (frivilligt)') }}</label>
                            <textarea id="pr_reject_reason" rows="2" maxlength="500" class="block w-full rounded-lg border-gray-300 shadow-xs focus:border-accent-500 focus:ring-accent-500" wire:model="rejectReason"></textarea>
                            <span class="text-sm text-gray-500">{{ __('Skriv vad som är fel med anmodan, inte uppgifter om personer.') }}</span>
                            <span class="text-sm text-gray-700">{{ __('Anmodan går tillbaka till den som skrev den, som kan ändra den eller skicka den till attest igen.') }}</span>
                            <span class="flex items-center gap-3">
                                <button type="button" class="text-sm font-medium text-accent-700 underline" wire:click="reject">{{ __('Ja, avvisa') }}</button>
                                <button type="button" class="text-sm text-gray-600 underline" x-on:click="rejecting = false">{{ __('Avbryt') }}</button>
                            </span>
                        </span>
                    </span>
                @endif
            </div>
            <x-input-error for="approve" class="mt-2" />
            <x-input-error for="reason" class="mt-2" />
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Rader')">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-stone-200 text-left text-gray-600">
                        <th class="py-2 pr-4 font-medium">{{ __('Vad') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Antal') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Estimerat à-pris') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Estimerat belopp') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Konto och dimensioner') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Leverantör') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Beställd') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($requisition->rows as $row)
                        <tr wire:key="pr-row-{{ $row->id }}">
                            <td class="py-2 pr-4">
                                @if($row->article)
                                    <span class="tabular-nums">{{ $row->article->number }}</span>
                                @endif
                                {{ $row->description }}
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ Quantity::format($row->quantity_milli) }} {{ $row->unit?->code }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row->estimated_price_ore === null ? '–' : Money::ofOre($row->estimated_price_ore)->format(withCurrency: false) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row->estimatedValueOre() === null ? '–' : Money::ofOre($row->estimatedValueOre())->format(withCurrency: false) }}</td>
                            <td class="py-2 pr-4">
                                @if($row->account){{ $row->account->number }}@else–@endif
                                @if($row->costCenter) · {{ $row->costCenter->code }}@endif
                                @if($row->project) · {{ $row->project->code }}@endif
                            </td>
                            <td class="py-2 pr-4">{{ $row->supplier?->name ?? '–' }}</td>
                            <td class="py-2 pr-4">
                                @if($row->purchaseOrderRow)
                                    <a href="{{ route('inventory.purchaseOrders.show', $row->purchaseOrderRow->purchase_order_id) }}" class="font-semibold text-accent-700 underline">{{ __('Inköpsorder :number', ['number' => $orders[$row->purchaseOrderRow->purchase_order_id] ?? '']) }}</a>
                                @else
                                    –
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-stone-300 font-semibold">
                        <td class="py-2 pr-4" colspan="3">{{ __('Summa exkl. moms') }}@if($requisition->hasUnpricedRows()) <span class="font-normal text-gray-600">{{ __('(rader utan pris räknas som noll)') }}</span>@endif</td>
                        <td class="py-2 pr-4 text-right tabular-nums">{{ $kr($requisition->estimatedOre()) }}</td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-ui.card>
</div>
