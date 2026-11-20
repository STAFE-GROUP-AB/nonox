@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<div class="space-y-6">
    @if($showForm)
        <x-ui.card :title="__('Nytt prisavtal')" :description="__('Avtalet blir ett utkast. Priserna lägger du in på avtalets sida, och sedan skickar du det för signering eller aktiverar det.')">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                    <label for="agreement_customer_search" class="block text-sm font-medium text-gray-700">{{ __('Kund') }}</label>
                    @if($customer)
                        <p class="mt-1 flex items-center gap-3 text-sm text-gray-900">
                            <span><span class="font-semibold tabular-nums">{{ $customer->number }}</span> {{ $customer->name }}</span>
                            <button type="button" class="text-sm text-accent-700 underline" wire:click="clearCustomer">{{ __('Byt kund') }}</button>
                        </p>
                    @else
                        <input id="agreement_customer_search" type="search" class="{{ $input }} mt-1" wire:model.live.debounce.250ms="customerSearch" placeholder="{{ __('Sök på kundnummer eller namn') }}" autocomplete="off" aria-describedby="agreement_customer_results">
                        <ul id="agreement_customer_results" class="mt-1 divide-y divide-gray-100 rounded-lg ring-1 ring-stone-200" @if($customerMatches->isEmpty()) hidden @endif>
                            @foreach($customerMatches as $match)
                                <li wire:key="customer-{{ $match->id }}">
                                    <button type="button" class="flex w-full items-center justify-between gap-4 px-3 py-2 text-left text-sm hover:bg-stone-50 focus-visible:bg-stone-50" wire:click="chooseCustomer({{ $match->id }})">
                                        <span><span class="font-semibold tabular-nums">{{ $match->number }}</span> {{ $match->name }}</span>
                                        <span class="tabular-nums text-gray-500">{{ $match->currency }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <x-input-error for="customer_id" :field="'customer_id'" class="mt-2" />
                </div>

                <x-ui.field for="agreement_title" :label="__('Rubrik')" error="title" class="sm:col-span-4">
                    <input id="agreement_title" type="text" class="{{ $input }} mt-1" wire:model="form.title" maxlength="150" placeholder="{{ __('Till exempel: Ramavtal 2027') }}">
                </x-ui.field>
                <x-ui.field for="agreement_currency" :label="__('Valuta')" error="currency" class="sm:col-span-2" :hint="__('Avtalet prissätter bara dokument i den här valutan.')">
                    <input id="agreement_currency" type="text" class="{{ $input }} mt-1 uppercase" wire:model="form.currency" maxlength="3" autocomplete="off">
                </x-ui.field>
                <x-ui.field for="agreement_from" :label="__('Gäller från')" error="valid_from" class="sm:col-span-3">
                    <input id="agreement_from" type="date" class="{{ $input }} mt-1" wire:model="form.valid_from">
                </x-ui.field>
                <x-ui.field for="agreement_to" :label="__('Gäller till och med (valfritt)')" error="valid_to" class="sm:col-span-3" :hint="__('Tomt betyder tills vidare.')">
                    <input id="agreement_to" type="date" class="{{ $input }} mt-1" wire:model="form.valid_to" @disabled(($form['crm_agreement_id'] ?? '') !== '')>
                </x-ui.field>
                @if($crmAgreements->isNotEmpty())
                    <x-ui.field for="agreement_crm" :label="__('Koppla till avtal i avtalsregistret (valfritt)')" error="crm_agreement_id" class="sm:col-span-6" :hint="__('Prisavtalet slutar då samma dag som avtalet i avtalsregistret, och påminnelserna kommer därifrån.')">
                        <select id="agreement_crm" class="{{ $input }} mt-1" wire:model.live="form.crm_agreement_id">
                            <option value="">{{ __('Inget') }}</option>
                            @foreach($crmAgreements as $crm)
                                <option value="{{ $crm->id }}">{{ $crm->title }} ({{ $crm->end_date?->toDateString() ?? __('tills vidare') }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <label class="flex items-start gap-2 text-sm text-gray-800 sm:col-span-6">
                    <input type="checkbox" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="form.allow_campaigns">
                    <span>{{ __('En kampanj med lägre pris får gå före avtalets pris') }}<span class="block text-gray-500">{{ __('Utan kryss gäller avtalets pris även när en kampanj är billigare.') }}</span></span>
                </label>

                <div class="flex items-center gap-4 sm:col-span-6">
                    <x-button wire:loading.attr="disabled">{{ __('Skapa utkast') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            @if($canManage && ! $showForm)
                <x-button type="button" wire:click="create">{{ __('Nytt prisavtal') }}</x-button>
            @endif
            <input type="search" class="{{ $input }} max-w-xs" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök kund, rubrik eller PA-nummer') }}" aria-label="{{ __('Sök prisavtal') }}">
            <select class="{{ $input }} max-w-48" wire:model.live="status" aria-label="{{ __('Visa status') }}">
                <option value="">{{ __('Alla statusar') }}</option>
                @foreach($statuses as $each)
                    <option value="{{ $each->value }}">{{ $each->label() }}</option>
                @endforeach
            </select>
        </div>

        @if($agreements->isEmpty())
            <x-ui.empty-state class="mt-6" :title="($search !== '' || $status !== '') ? __('Inget prisavtal matchar') : __('Inga prisavtal ännu')">
                @if($search !== '' || $status !== '')
                    {{ __('Ändra sökningen eller filtret.') }}
                @else
                    {{ __('Ett prisavtal ger en kund egna priser eller rabatter under en period. Skapa ett utkast, lägg in priserna och skicka det för signering. När kunden har signerat får fakturor, offerter och order avtalets priser av sig själva.') }}
                @endif
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ __('Prisavtal') }}</caption>
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            @foreach(['number' => __('Nummer'), 'customer' => __('Kund')] as $column => $label)
                                <th scope="col" class="py-2 pr-4 font-medium" aria-sort="{{ $sort === $column ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                    <button type="button" class="font-medium uppercase tracking-wide hover:text-gray-800" wire:click="sortBy('{{ $column }}')">{{ $label }}@if($sort === $column) <span aria-hidden="true">{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif</button>
                                </th>
                            @endforeach
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Rubrik') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium" aria-sort="{{ $sort === 'valid_from' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                <button type="button" class="font-medium uppercase tracking-wide hover:text-gray-800" wire:click="sortBy('valid_from')">{{ __('Gäller') }}@if($sort === 'valid_from') <span aria-hidden="true">{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif</button>
                            </th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Priser') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($agreements as $agreement)
                            <tr wire:key="agreement-{{ $agreement->id }}">
                                <td class="py-3 pr-4 font-semibold tabular-nums text-gray-900">
                                    <a href="{{ route('pricing.agreements.show', $agreement->id) }}" class="text-accent-700 hover:underline">{{ $agreement->reference() }}</a>
                                </td>
                                <td class="py-3 pr-4">{{ $agreement->customer->name ?? '' }}</td>
                                <td class="py-3 pr-4">
                                    {{ $agreement->title }}
                                    @if($agreement->supersedes)
                                        <span class="block text-xs text-gray-500">{{ __('Ny version av :reference', ['reference' => $agreement->supersedes->reference()]) }}</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 tabular-nums text-gray-700">{{ $agreement->valid_from->toDateString() }} – {{ $agreement->lastDay()?->toDateString() ?? __('tills vidare') }} <span class="text-gray-500">{{ $agreement->currency }}</span></td>
                                <td class="py-3 pr-4"><x-ui.badge :tone="$agreement->status->tone()">{{ $agreement->status->label() }}</x-ui.badge></td>
                                <td class="py-3 pr-4 text-right tabular-nums">{{ $agreement->items_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $agreements->links() }}</div>
        @endif
    </x-ui.card>
</div>
