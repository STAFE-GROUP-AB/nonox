@php
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
@endphp

<div data-paired-draft>
    @if($pairs->isNotEmpty() || ($mayPair && $counterparts->isNotEmpty()))
        <x-ui.card :title="__('Koncernföretag')" :description="__('Ett mellanhavande inom koncernen utan faktura, till exempel ett lån, ränta eller ett koncernbidrag, bokförs i båda företagen.')">
            @if($pairs->isNotEmpty())
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach($pairs as $pair)
                        <li class="py-2" wire:key="pair-{{ $pair->id }}">
                            <span class="font-medium">{{ $pair->kind->label() }}</span>
                            · {{ __('motpart :number', ['number' => $pair->counterparty_org_number]) }}
                            · @if($pair->counterparty_voucher_ref){{ __('motpartens verifikation :reference', ['reference' => $pair->counterparty_voucher_ref]) }}@else<span class="text-gray-500">{{ __('motpartens verifikation inte angiven') }}</span>@endif
                            @if($mayEdit && $editPairId !== $pair->id)
                                <button type="button" class="ms-2 text-sm text-accent-600 underline" wire:click="editReference({{ $pair->id }})">{{ __('Ange motpartens verifikation') }}</button>
                            @endif
                            @if($editPairId === $pair->id)
                                <form wire:submit="saveReference" class="mt-2 flex flex-wrap items-end gap-2">
                                    <div>
                                        <label for="pair_reference" class="block text-xs text-gray-600">{{ __('Serie och nummer hos motparten') }}</label>
                                        <input id="pair_reference" type="text" class="{{ $input }}" wire:model="reference" maxlength="40" placeholder="{{ __('till exempel A12') }}">
                                        <x-input-error for="reference" class="mt-1" />
                                    </div>
                                    <x-button>{{ __('Spara') }}</x-button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if($mayPair && $counterparts->isNotEmpty())
                @if(! $open)
                    <div @class(['mt-4' => $pairs->isNotEmpty()])>
                        <x-secondary-button type="button" wire:click="start">{{ __('Skapa motsvarande utkast') }}</x-secondary-button>
                        <p class="mt-2 text-sm text-gray-500">{{ __('NONOX föreslår raderna med debet och kredit omvända och gör ett utkast i koncernföretaget. Utkastet bokförs av den som bokför där.') }}</p>
                    </div>
                @else
                    <form wire:submit="create" class="mt-4 space-y-4" data-paired-draft-form>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="paired_counterpart" class="block text-sm font-medium text-gray-700">{{ __('Koncernföretag') }}</label>
                                <select id="paired_counterpart" class="{{ $input }} mt-1" wire:model="counterpartId">
                                    <option value="">{{ __('Välj företag') }}</option>
                                    @foreach($counterparts as $counterpart)
                                        <option value="{{ $counterpart->id }}">{{ $counterpart->name }}, {{ $counterpart->org_number }}</option>
                                    @endforeach
                                </select>
                                <x-input-error for="counterpart" class="mt-1" />
                            </div>
                            <div>
                                <label for="paired_kind" class="block text-sm font-medium text-gray-700">{{ __('Mellanhavande i det här företaget') }}</label>
                                <select id="paired_kind" class="{{ $input }} mt-1" wire:model.live="kind">
                                    <option value="">{{ __('Välj') }}</option>
                                    @foreach($kinds as $option)
                                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                    @endforeach
                                </select>
                                @if($kind !== '' && $mirrorOf($kind))
                                    <p class="mt-1 text-xs text-gray-500">{{ __('Hos motparten: :kind', ['kind' => $mirrorOf($kind)->label()]) }}</p>
                                @endif
                                <x-input-error for="kind" class="mt-1" />
                            </div>
                            <div>
                                <label for="paired_date" class="block text-sm font-medium text-gray-700">{{ __('Datum') }}</label>
                                <input id="paired_date" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="voucherDate" placeholder="ÅÅÅÅ-MM-DD">
                                <x-input-error for="voucher_date" class="mt-1" />
                            </div>
                            <div>
                                <label for="paired_series" class="block text-sm font-medium text-gray-700">{{ __('Serie hos motparten') }}</label>
                                <input id="paired_series" type="text" class="{{ $input }} mt-1" wire:model="series" maxlength="3">
                                <x-input-error for="series" class="mt-1" />
                            </div>
                            <div class="sm:col-span-2">
                                <label for="paired_description" class="block text-sm font-medium text-gray-700">{{ __('Beskrivning') }}</label>
                                <input id="paired_description" type="text" class="{{ $input }} mt-1" wire:model="description" maxlength="255">
                                <x-input-error for="description" class="mt-1" />
                            </div>
                        </div>

                        <p class="text-sm text-gray-600">{{ __('Kontona är föreslagna. Kontrollera att de finns i motpartens kontoplan och byt till exempel en fordran mot en skuld. Beloppen är verifikationens och kan inte ändras här.') }}</p>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                                        <th class="py-2 pr-2 font-medium">{{ __('Konto hos motparten') }}</th>
                                        <th class="py-2 pr-2 font-medium text-right">{{ __('Debet') }}</th>
                                        <th class="py-2 pr-2 font-medium text-right">{{ __('Kredit') }}</th>
                                        <th class="py-2 pr-2 font-medium">{{ __('Radtext') }}</th>
                                        <th class="py-2 pr-2 font-medium">{{ __('Kostnadsställe') }}</th>
                                        <th class="py-2 font-medium">{{ __('Projekt') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($rows as $index => $row)
                                        <tr wire:key="paired-row-{{ $index }}">
                                            <td class="py-1.5 pr-2"><input type="text" inputmode="numeric" class="{{ $input }} w-24" aria-label="{{ __('Konto rad :line', ['line' => $index + 1]) }}" wire:model="rows.{{ $index }}.account">@if($amounts[$index]['check'] ?? false)<span class="mt-1 block text-xs text-amber-800" data-paired-check>{{ __('Kontrollera kontot hos motparten') }}</span>@endif</td>
                                            <td class="py-1.5 pr-2 text-right tabular-nums">@if(($amounts[$index]['debit_ore'] ?? 0) > 0)<x-ui.amount :money="Money::ofOre($amounts[$index]['debit_ore'])" :currency="false" />@endif</td>
                                            <td class="py-1.5 pr-2 text-right tabular-nums">@if(($amounts[$index]['credit_ore'] ?? 0) > 0)<x-ui.amount :money="Money::ofOre($amounts[$index]['credit_ore'])" :currency="false" />@endif</td>
                                            <td class="py-1.5 pr-2"><input type="text" class="{{ $input }}" aria-label="{{ __('Radtext rad :line', ['line' => $index + 1]) }}" wire:model="rows.{{ $index }}.description"></td>
                                            <td class="py-1.5 pr-2"><input type="text" class="{{ $input }} w-24" aria-label="{{ __('Kostnadsställe rad :line', ['line' => $index + 1]) }}" wire:model="rows.{{ $index }}.cost_center"></td>
                                            <td class="py-1.5"><input type="text" class="{{ $input }} w-24" aria-label="{{ __('Projekt rad :line', ['line' => $index + 1]) }}" wire:model="rows.{{ $index }}.project"></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <x-input-error for="rows" class="mt-1" />
                        @foreach($errors->getMessages() as $key => $messages)
                            @if(str_starts_with($key, 'rows.'))
                                <p class="text-sm text-red-600">{{ $messages[0] }}</p>
                            @endif
                        @endforeach

                        <div class="flex flex-wrap gap-3">
                            <x-button wire:loading.attr="disabled" wire:confirm="{{ __('Ett utkast skapas i koncernföretaget. Det bokförs inte förrän någon där bokför det.') }}">{{ __('Skapa utkastet') }}</x-button>
                            <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                        </div>
                    </form>
                @endif
            @endif
        </x-ui.card>
    @endif
</div>
