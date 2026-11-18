@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $money = fn (?int $ore): string => $ore === null ? '–' : App\Support\Money\Money::ofOre($ore)->format();
    $share = fn (int $bp): string => rtrim(rtrim(number_format($bp / 100, 2, ',', ''), '0'), ',')."\u{00A0}%";
@endphp
<div class="space-y-6" data-billing-plan>
    <x-ui.card :title="__('Faktureringsplan')" :description="__('Delfaktureringar med datum och belopp eller andel av kontraktssumman. Fakturera skapar ett fakturautkast till projektets kund med projektet på raden. Ingenting skickas eller bokförs förrän du skickar fakturan.')">
        <dl class="mb-4 grid gap-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-gray-500">{{ __('Kontraktssumma') }}</dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums text-ink">{{ $money($contract) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('Planerat i planen') }}</dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums text-ink">{{ $money($planned) }}</dd>
                @if($contract !== null && $planned !== $contract)
                    <dd class="mt-1 text-gray-600 tabular-nums">{{ __('Skillnad mot kontraktssumman: :amount', ['amount' => $money($contract - $planned)]) }}</dd>
                @endif
            </div>
            <div>
                <dt class="text-gray-500">{{ __('Fakturerat ur planen') }}</dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums text-ink">{{ $money($invoiced) }}</dd>
                @if($drafted > 0)
                    <dd class="mt-1 text-gray-600 tabular-nums">{{ __('Utkast: :amount', ['amount' => $money($drafted)]) }}</dd>
                @endif
            </div>
        </dl>

        @if($project->customer === null)
            <p class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('Projektet har ingen kund. Välj kund under Uppgifter i projektregistret innan du fakturerar.') }}</p>
        @endif

        @if($milestones->isEmpty() && $editing === null)
            <x-ui.empty-state :title="__('Ingen faktureringsplan ännu')">{{ __('Lägg till delfaktureringar, till exempel en per etapp, så ser du vad som är fakturerat och vad som återstår.') }}</x-ui.empty-state>
        @elseif($milestones->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="py-2 pe-4 font-medium">{{ __('Delfakturering') }}</th>
                            <th scope="col" class="py-2 pe-4 font-medium">{{ __('Datum') }}</th>
                            <th scope="col" class="py-2 pe-4 text-right font-medium">{{ __('Belopp exkl. moms') }}</th>
                            <th scope="col" class="py-2 pe-4 font-medium">{{ __('Status') }}</th>
                            <th scope="col" class="py-2 font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($milestones as $milestone)
                            @php($status = $milestone->effectiveStatus())
                            @php($token = $milestone->versionToken())
                            <tr wire:key="milestone-{{ $milestone->id }}">
                                <td class="py-2 pe-4">{{ $milestone->description }}</td>
                                <td class="py-2 pe-4 tabular-nums whitespace-nowrap">{{ $milestone->due_on?->toDateString() ?? '–' }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums whitespace-nowrap">
                                    {{ $money($milestone->amountFor($contract)) }}
                                    @if($milestone->share_bp !== null)
                                        <span class="block text-xs text-gray-500">{{ __(':share av kontraktssumman', ['share' => $share($milestone->share_bp)]) }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pe-4">
                                    <x-ui.badge>{{ $status->label() }}</x-ui.badge>
                                    @if($status === App\Domain\Projects\MilestoneStatus::Invoiced && $milestone->invoice !== null)
                                        <a href="{{ route($milestone->invoice->status === App\Domain\Invoicing\InvoiceStatus::Draft ? 'invoicing.invoices.edit' : 'invoicing.invoices.show', $milestone->invoice->id) }}" class="ms-2 text-accent-700 hover:underline">{{ $milestone->invoice->number === null ? __('Utkast') : __('Faktura :number', ['number' => $milestone->invoice->number]) }}</a>
                                    @endif
                                </td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    @if($status !== App\Domain\Projects\MilestoneStatus::Invoiced)
                                        @if($canInvoice && $status === App\Domain\Projects\MilestoneStatus::Ready)
                                            <x-button type="button" wire:click="invoice({{ $milestone->id }}, '{{ $token }}')" wire:loading.attr="disabled" wire:confirm="{{ __('Skapa ett fakturautkast till projektets kund för den här delfaktureringen? Ingenting skickas eller bokförs förrän du skickar fakturan.') }}">{{ __('Fakturera') }}</x-button>
                                        @endif
                                        @if($canManage)
                                            @if($status === App\Domain\Projects\MilestoneStatus::Planned)
                                                <button type="button" wire:click="markReady({{ $milestone->id }}, '{{ $token }}')" class="ms-2 text-sm font-medium text-accent-700 hover:underline">{{ __('Markera som klar') }}</button>
                                            @endif
                                            <button type="button" wire:click="edit({{ $milestone->id }})" class="ms-2 text-sm font-medium text-gray-700 hover:underline">{{ __('Ändra') }}</button>
                                            <button type="button" wire:click="delete({{ $milestone->id }}, '{{ $token }}')" wire:confirm="{{ __('Ta bort delfaktureringen ur planen?') }}" class="ms-2 text-sm font-medium text-gray-700 hover:underline">{{ __('Ta bort') }}</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($editing !== null)
            <form wire:submit="save" class="mt-6 grid gap-4 rounded-2xl bg-stone-50 p-4 sm:grid-cols-2" data-milestone-form>
                <x-ui.field for="milestone_description" :label="__('Beskrivning')" class="sm:col-span-2">
                    <input id="milestone_description" type="text" maxlength="255" wire:model="form.description" class="{{ $input }} mt-1">
                    <x-input-error for="description" class="mt-1" />
                </x-ui.field>
                <x-ui.field for="milestone_due_on" :label="__('Datum')">
                    <input id="milestone_due_on" type="date" wire:model="form.due_on" class="{{ $input }} mt-1">
                    <x-input-error for="due_on" class="mt-1" />
                </x-ui.field>
                <x-ui.field for="milestone_status" :label="__('Status')">
                    <select id="milestone_status" wire:model="form.status" class="{{ $input }} mt-1">
                        <option value="PLANNED">{{ __('Planerad') }}</option>
                        <option value="READY">{{ __('Klar att fakturera') }}</option>
                    </select>
                </x-ui.field>
                <fieldset class="sm:col-span-2">
                    <legend class="text-sm font-medium text-gray-700">{{ __('Belopp') }}</legend>
                    <div class="mt-2 flex flex-wrap gap-4 text-sm">
                        <label class="inline-flex items-center gap-2"><input type="radio" value="AMOUNT" wire:model.live="form.kind"> {{ __('Belopp exkl. moms') }}</label>
                        <label class="inline-flex items-center gap-2"><input type="radio" value="SHARE" wire:model.live="form.kind"> {{ __('Andel av kontraktssumman') }}</label>
                    </div>
                </fieldset>
                @if($form['kind'] === 'SHARE')
                    <x-ui.field for="milestone_share" :label="__('Andel i procent')">
                        <input id="milestone_share" type="text" inputmode="decimal" wire:model="form.share" class="{{ $input }} mt-1 text-right tabular-nums">
                        <x-input-error for="share" class="mt-1" />
                    </x-ui.field>
                @else
                    <x-ui.field for="milestone_amount" :label="__('Belopp exkl. moms')">
                        <input id="milestone_amount" type="text" inputmode="decimal" wire:model="form.amount" class="{{ $input }} mt-1 text-right tabular-nums">
                        <x-input-error for="amount" class="mt-1" />
                    </x-ui.field>
                @endif
                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara delfakturering') }}</x-button>
                    <button type="button" wire:click="cancel" class="text-sm font-medium text-gray-700 underline">{{ __('Avbryt') }}</button>
                </div>
            </form>
        @elseif($canManage)
            <div class="mt-4">
                <x-button type="button" wire:click="create">{{ __('Lägg till delfakturering') }}</x-button>
            </div>
        @endif
    </x-ui.card>

    @if($workOrders->isNotEmpty())
        <x-ui.card :title="__('A conto på arbetsorder')" :description="__('Arbetsorder på projektet har egna delbetalningar, som faktureras från arbetsordern.')">
            @foreach($workOrders as $workOrder)
                <div wire:key="work-order-{{ $workOrder->id }}" class="mt-2 first:mt-0">
                    <a href="{{ route('workOrders.show', $workOrder->id) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Arbetsorder :number', ['number' => $workOrder->number]) }}</a>
                    @if($workOrder->instalments->isEmpty())
                        <p class="text-sm text-gray-600">{{ __('Ingen a conto-plan.') }}</p>
                    @else
                        <ul class="mt-1 space-y-1 text-sm">
                            @foreach($workOrder->instalments as $instalment)
                                <li class="flex flex-wrap justify-between gap-2">
                                    <span>{{ $instalment->label }}@if($instalment->due_on) · <span class="tabular-nums">{{ $instalment->due_on->toDateString() }}</span>@endif</span>
                                    <span class="tabular-nums">{{ $money($instalment->amount_ore->ore) }} · {{ $instalment->invoiceLink === null ? __('Inte fakturerad') : __('Fakturerad') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </x-ui.card>
    @endif
</div>
