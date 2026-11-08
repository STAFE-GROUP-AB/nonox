@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($money = fn (int $ore) => App\Support\Money\Money::ofOre($ore))
@php($qty = fn (int $milli) => App\Modules\Inventory\Domain\Quantity::format($milli))
@php($pct = fn (?int $bp) => App\Modules\Kitchen\Livewire\RecipePage::percent($bp))
<div class="space-y-6">
    <x-ui.card>
        @if($counts->count() < 2)
            <x-ui.empty-state :title="__('Det behövs två inventeringar')">
                {{ __('Avvikelsen räknas mellan två slutförda inventeringar av samma lagerställe, till exempel varje måndag. Inventera under Lager → Inventering eller med mobilen.') }}
            </x-ui.empty-state>
        @else
            <div class="mb-4 flex flex-wrap items-end gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">{{ __('Från inventering') }}</span>
                    <select wire:model.live="openingId" class="{{ $input }}">
                        @foreach($counts as $count)
                            <option value="{{ $count->id }}">{{ __('Nr :number, :date', ['number' => $count->number, 'date' => $count->counted_on->toDateString()]) }} {{ $count->location?->full_code ?? __('hela lagret') }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">{{ __('Till inventering') }}</span>
                    <select wire:model.live="closingId" class="{{ $input }}">
                        @foreach($counts as $count)
                            <option value="{{ $count->id }}">{{ __('Nr :number, :date', ['number' => $count->number, 'date' => $count->counted_on->toDateString()]) }} {{ $count->location?->full_code ?? __('hela lagret') }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            @if($error)
                <p class="rounded-2xl bg-accent-50 p-4 text-sm text-accent-800" role="alert">{{ $error }}</p>
            @elseif($report)
                @if($report->lines === [])
                    <x-ui.empty-state :title="__('Inga varor finns i båda inventeringarna')">{{ __('Avvikelsen räknas för varor som är inventerade både vid start och slut.') }}</x-ui.empty-state>
                @endif
                @foreach($classes as $class)
                    @php($lines = array_values(array_filter($report->lines, fn (array $line): bool => $line['stock_class'] === $class->value)))
                    @if($lines !== [])
                        <h3 class="mb-2 mt-4 text-sm font-semibold text-ink">{{ $class->label() }}</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-stone-200 text-left text-gray-500">
                                        <th class="py-2 pr-3 font-medium">{{ __('Vara') }}</th>
                                        <th class="py-2 pr-3 text-right font-medium">{{ __('Ingående') }}</th>
                                        <th class="py-2 pr-3 text-right font-medium">{{ __('Inlevererat') }}</th>
                                        <th class="py-2 pr-3 text-right font-medium">{{ __('Sålt enligt recept') }}</th>
                                        <th class="py-2 pr-3 text-right font-medium">{{ __('Svinn') }}</th>
                                        <th class="py-2 pr-3 text-right font-medium">{{ __('Personalmat') }}</th>
                                        <th class="py-2 pr-3 text-right font-medium">{{ __('Övrigt ut') }}</th>
                                        <th class="py-2 pr-3 text-right font-medium">{{ __('Utgående') }}</th>
                                        <th class="py-2 pr-3 text-right font-medium">{{ __('Oförklarat') }}</th>
                                        <th class="py-2 pr-3 text-right font-medium">{{ __('Värde') }}</th>
                                        <th class="py-2 pr-3 text-right font-medium">{{ __('Av sålt') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-100">
                                    @foreach($lines as $line)
                                        <tr wire:key="variance-{{ $line['article_id'] }}">
                                            <td class="py-2 pr-3">{{ $line['name'] }} <span class="text-xs text-gray-500">{{ $line['unit'] }}</span></td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ $qty($line['opening_milli']) }}</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ $qty($line['receipts_milli']) }}</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ $qty($line['theoretical_milli']) }}@if($drawsOnSale)<span class="block text-xs text-gray-500">{{ __('dragit :quantity', ['quantity' => $qty($line['drawn_milli'])]) }}</span>@endif</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ $qty($line['waste_milli']) }}@if($line['waste_ore'] !== null && $line['waste_milli'] !== 0)<span class="block text-xs text-gray-500">{{ $money($line['waste_ore'])->format() }}</span>@endif</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ $qty($line['staff_milli']) }}</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ $qty($line['other_milli']) }}</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ $qty($line['closing_milli']) }}</td>
                                            <td class="py-2 pr-3 text-right font-semibold tabular-nums {{ $line['variance_milli'] > 0 ? 'text-accent-700' : '' }}">{{ $qty($line['variance_milli']) }}@if($drawsOnSale)<span class="block text-xs font-normal text-gray-500">{{ __('inventering :quantity', ['quantity' => $qty(-$line['count_difference_milli'])]) }}</span>@endif</td>
                                            <td class="py-2 pr-3 text-right">@if($line['variance_ore'] !== null)<x-ui.amount :money="$money($line['variance_ore'])" />@else{{ __('Saknar pris') }}@endif</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ $pct($line['share_bp']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endforeach
                <p class="mt-4 text-sm text-gray-600">{{ __('Sålt enligt kassans försäljningsrader, inte kassans Z-rapport. Tillval som extra bacon läses inte och kan ge avvikelse. Värdet räknas med lagervärdet per enhet vid den sista inventeringen. En positiv avvikelse är förbrukning som inte förklaras.') }}</p>

                @if($report->withoutRecipe !== [])
                    <h3 class="mb-2 mt-6 text-sm font-semibold text-ink">{{ __('Sålt utan recept') }}</h3>
                    <ul class="divide-y divide-stone-200 text-sm">
                        @foreach($report->withoutRecipe as $articleId => $milli)
                            <li class="flex justify-between py-2" wire:key="norecipe-{{ $articleId }}"><span>{{ $report->dishNames[$articleId] ?? $articleId }}</span><span class="tabular-nums">{{ __(':quantity portioner', ['quantity' => $qty($milli)]) }}</span></li>
                        @endforeach
                    </ul>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Rätterna hade inget recept den dagen de såldes. Deras råvaror ingår inte i det sålda, och NONOX gissar dem inte.') }}</p>
                @endif

                @if($report->late !== [])
                    <h3 class="mb-2 mt-6 text-sm font-semibold text-ink">{{ __('Bokförd i efterhand') }}</h3>
                    <ul class="divide-y divide-stone-200 text-sm">
                        @foreach($report->late as $late)
                            <li class="flex justify-between py-2" wire:key="late-{{ $late['id'] }}"><span>{{ $late['date'] }} {{ $late['article'] }} · {{ $late['type'] }} · {{ $late['excluded'] ? __('registrerad efter inventeringen, inte med i summorna') : __('registrerad efter lagervärderingen') }}</span><span class="tabular-nums">{{ $qty($late['quantity_milli']) }}</span></li>
                        @endforeach
                    </ul>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Rörelserna är daterade i perioden men registrerades efter att inventeringen slutfördes eller lagret värderades.') }}</p>
                @endif
                @if($report->unlocatedRows > 0)
                    <p class="mt-4 text-sm text-gray-600">{{ __('Försäljning från kassor utan lagerställe räknas med i det sålda, eftersom NONOX inte vet var de drar sina varor.') }}</p>
                @endif
            @endif
        @endif
    </x-ui.card>

    @if($waits->isNotEmpty())
        <x-ui.card :title="__('Väntar på lager')" :description="__('Försäljningen skulle ha dragit mer än lagret har. Raden prövas igen varje natt. Dra den nu när lagret är rättat, eller lämna den till nästa inventering.')">
            <ul class="divide-y divide-stone-200 text-sm">
                @foreach($waits as $wait)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="wait-{{ $wait->id }}">
                        <span>{{ $wait->business_date->toDateString() }} · {{ $wait->article?->name }} · {{ $qty($wait->quantity_milli) }} {{ $wait->article?->unit }} · {{ $wait->location?->full_code }}</span>
                        <span class="flex gap-3">
                            <button type="button" wire:click="resolve({{ $wait->id }}, true)" wire:confirm="{{ __('Dra ingrediensen från lagret nu?') }}" class="text-sm font-semibold text-accent-700 underline">{{ __('Dra nu') }}</button>
                            <button type="button" wire:click="resolve({{ $wait->id }}, false)" wire:confirm="{{ __('Lämna raden till nästa inventering? Den dras inte från lagret.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Lämna till inventeringen') }}</button>
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
