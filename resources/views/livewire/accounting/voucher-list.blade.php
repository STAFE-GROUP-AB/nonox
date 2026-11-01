@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<div class="space-y-6">
    {{-- Result of the nightly integrity check (AGENTS.md §6.4). --}}
    <div @class([
        'flex flex-wrap items-center justify-between gap-3 rounded-xl border px-5 py-4 text-sm',
        'border-green-200 bg-green-50 text-green-900' => $lastCheck?->payload['ok'] ?? false,
        'border-accent-200 bg-accent-50 text-accent-900' => $lastCheck && ! ($lastCheck->payload['ok'] ?? false),
        'border-gray-200 bg-white text-gray-700' => ! $lastCheck,
    ])>
        <div>
            @if(! $lastCheck)
                <p class="font-medium">{{ __('Bokföringen har inte kontrollerats ännu.') }}</p>
                <p>{{ __('Varje natt räknar systemet om alla verifikationer och kontrollerar att inget har ändrats.') }}</p>
            @elseif($lastCheck->payload['ok'] ?? false)
                <p class="font-medium">✓ {{ __('Bokföringen är intakt.') }}</p>
                <p>{{ __('Kontrollerad :when. Inget har ändrats i efterhand.', ['when' => $lastCheck->created_at->locale('sv')->diffForHumans()]) }}</p>
            @else
                <p class="font-medium">{{ __('Kontrollen hittade avvikelser') }}</p>
                <ul class="mt-1 list-disc ps-5">
                    @foreach($lastCheck->payload['problems'] ?? [] as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
        <button type="button" class="font-semibold underline" wire:click="checkLedger" wire:loading.attr="disabled">{{ __('Kontrollera nu') }}</button>
    </div>

    <x-ui.card>
        <x-slot:cta>
            @if($canCreate)
                <a href="{{ route('accounting.vouchers.create') }}" class="inline-flex items-center px-4 py-2 bg-accent-600 rounded-lg font-semibold text-sm text-white hover:bg-accent-700">{{ __('Ny verifikation') }}</a>
            @endif
        </x-slot:cta>

        <div class="flex flex-wrap items-center gap-3">
            <label for="voucher_search" class="sr-only">{{ __('Sök verifikation') }}</label>
            <input id="voucher_search" type="search" class="{{ $input }} sm:max-w-xs" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på text eller nummer, t.ex. A12') }}">

            <label for="voucher_status" class="sr-only">{{ __('Visa') }}</label>
            <select id="voucher_status" class="{{ $input }} sm:max-w-48" wire:model.live="status">
                <option value="alla">{{ __('Alla') }}</option>
                <option value="bokforda">{{ __('Bokförda') }}</option>
                <option value="utkast">{{ __('Utkast') }}</option>
            </select>

            <label for="voucher_year" class="sr-only">{{ __('Räkenskapsår') }}</label>
            <select id="voucher_year" class="{{ $input }} sm:max-w-48" wire:model.live="fiscalYearId">
                @foreach($fiscalYears as $fiscalYear)
                    <option value="{{ $fiscalYear->id }}">{{ $fiscalYear->label() }}</option>
                @endforeach
            </select>
        </div>

        @if($vouchers->isEmpty())
            <x-ui.empty-state class="mt-6" :title="$search === '' ? __('Inga verifikationer ännu') : __('Inga verifikationer hittades')">
                @if($search === '')
                    {{ __('En verifikation är en bokförd händelse, till exempel ett köp eller en försäljning. Börja med Ny verifikation.') }}
                @else
                    {{ __('Prova ett annat sökord.') }}
                @endif
            </x-ui.empty-state>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th scope="col" class="py-2 pr-4 font-medium w-20">{{ __('Ver') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium w-28">{{ __('Datum') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Beskrivning') }}</th>
                            <th scope="col" class="py-2 font-medium text-right">{{ __('Belopp') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($vouchers as $voucher)
                            <tr wire:key="voucher-{{ $voucher->id }}" class="hover:bg-gray-50">
                                <td class="py-2.5 pr-4 font-semibold tabular-nums">
                                    <a href="{{ route('accounting.vouchers.show', $voucher) }}" class="hover:text-accent-700">
                                        @if($voucher->isPosted())
                                            {{ $voucher->reference() }}
                                        @else
                                            <x-ui.badge>{{ __('Utkast') }}</x-ui.badge>
                                        @endif
                                    </a>
                                </td>
                                <td class="py-2.5 pr-4 tabular-nums text-gray-600">{{ $voucher->voucher_date->toDateString() }}</td>
                                <td class="py-2.5 pr-4">
                                    <a href="{{ route('accounting.vouchers.show', $voucher) }}" class="hover:text-accent-700">{{ $voucher->description }}</a>
                                    @if($voucher->corrected_by_voucher_id)
                                        <x-ui.badge class="ms-2">{{ __('Rättad') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="py-2.5 text-right">
                                    <x-ui.amount :money="App\Support\Money\Money::ofOre((int) $voucher->debit_total)" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6">{{ $vouchers->links() }}</div>
        @endif
    </x-ui.card>
</div>
