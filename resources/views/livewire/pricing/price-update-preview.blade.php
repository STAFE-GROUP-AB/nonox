@php
    use App\Domain\Pricing\PriceUpdateLineStatus;
    use App\Domain\Pricing\PriceUpdateStatus;
    use App\Domain\Pricing\PriceUpdateTarget;
    use App\Domain\Pricing\PriceInput;
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $isPreview = $run->status === PriceUpdateStatus::Preview;
    $rule = $run->rule ?? [];
    $flagLabels = [
        'below_cost' => __('Under inköpspris'),
        'big_change' => __('Ändras mer än 25 %'),
        'rounding_moved' => __('Avrundningen flyttar mer än 2 %'),
        'below_floor' => __('Under lägsta täckningsgrad'),
    ];
    $price = fn (?int $minor, ?int $discount, string $currency): string => $minor !== null
        ? Money::ofOre($minor, $currency)->format()
        : ($discount !== null ? __(':percent % rabatt', ['percent' => PriceInput::basisPointsToPercent($discount)]) : '–');
@endphp

<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <dl class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-gray-500">{{ __('Ändring') }}</dt>
                    <dd class="font-medium text-gray-900">
                        {{ $run->kind->label() }}
                        @if(isset($rule['percent_bp']))
                            {{ ($rule['percent_bp'] > 0 ? '+' : '').PriceInput::basisPointsToPercent((int) $rule['percent_bp']) }} %
                        @elseif(isset($rule['amount_minor']))
                            {{ ($rule['amount_minor'] > 0 ? '+' : '').Money::ofOre((int) $rule['amount_minor'])->format() }}
                        @elseif(isset($rule['margin_bp']))
                            {{ PriceInput::basisPointsToPercent((int) $rule['margin_bp']) }} %
                        @elseif(isset($rule['index_old']))
                            {{ str_replace('.', ',', (string) $rule['index_old']) }} → {{ str_replace('.', ',', (string) $rule['index_new']) }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ __('Gäller från') }}</dt>
                    <dd class="font-medium tabular-nums text-gray-900">{{ $run->effective_date->toDateString() }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ __('Avrundning') }}</dt>
                    <dd class="font-medium text-gray-900">{{ $run->rounding_rule->label() }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ __('Status') }}</dt>
                    <dd><x-ui.badge :tone="match ($run->status) { PriceUpdateStatus::Applied => 'success', PriceUpdateStatus::Scheduled => 'accent', PriceUpdateStatus::Reverted => 'neutral', default => 'warning' }">{{ $run->status->label() }}</x-ui.badge></dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ $isPreview ? __('Ändras') : __('Ändrade') }}</dt>
                    <dd class="font-medium tabular-nums text-gray-900">{{ (int) $totals->changing }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ __('Kan inte ändras') }}</dt>
                    <dd class="font-medium tabular-nums {{ (int) $totals->refused > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ (int) $totals->refused }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ __('Oförändrade') }}</dt>
                    <dd class="font-medium tabular-nums text-gray-900">{{ (int) $totals->unchanged }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ __('Genomsnittlig ändring') }}</dt>
                    <dd class="font-medium tabular-nums text-gray-900">{{ $totals->average_bp === null ? '–' : PriceInput::basisPointsToPercent((int) round((float) $totals->average_bp)).' %' }}</dd>
                </div>
            </dl>

            <div class="flex flex-col items-end gap-2">
                @if($canManage && $isPreview)
                    <x-button type="button" wire:click="apply" wire:loading.attr="disabled" wire:target="apply"
                        wire:confirm="{{ $run->effective_date->isToday()
                            ? __('Genomför prisuppdateringen? :count priser ändras från i dag. De gamla priserna finns kvar i historiken, och uppdateringen kan återställas i dag.', ['count' => (int) $totals->changing - $excludedCount])
                            : __('Genomför prisuppdateringen? :count priser ändras från :date. Fram till dess gäller de gamla, och uppdateringen kan återställas till och med den dagen.', ['count' => (int) $totals->changing - $excludedCount, 'date' => $run->effective_date->toDateString()]) }}">
                        {{ __('Genomför') }}
                    </x-button>
                    @if($excludedCount > 0)
                        <span class="text-sm text-gray-600">{{ trans_choice(':count pris lämnas utanför|:count priser lämnas utanför', $excludedCount, ['count' => $excludedCount]) }}</span>
                    @endif
                @endif
                @if($canManage && $run->canRevert())
                    <x-secondary-button type="button" wire:click="revert" wire:loading.attr="disabled" wire:target="revert"
                        wire:confirm="{{ __('Återställ prisuppdateringen? De gamla priserna gäller igen. Inget raderas: de nya priserna arkiveras och ändringen finns kvar i historiken.') }}">
                        {{ __('Återställ') }}
                    </x-secondary-button>
                @endif
                <button type="button" class="text-sm font-medium text-accent-700 underline" wire:click="downloadDiff">{{ __('Ladda ner som CSV') }}</button>
            </div>
        </div>

        @if($run->status === PriceUpdateStatus::Scheduled)
            <p class="mt-4 text-sm text-gray-600">{{ __('Priserna i prislistorna är inlagda med startdatum. Grundpriserna skrivs i artikelregistret natten till den dag de börjar gälla.') }}</p>
        @elseif($run->status === PriceUpdateStatus::Reverted)
            <p class="mt-4 text-sm text-gray-600">{{ __('Återställd :date. De priser uppdateringen lade in finns kvar i prislistornas historik som arkiverade.', ['date' => $run->reverted_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i')]) }}</p>
        @elseif($isPreview && $run->file_deleted_at !== null)
            <p class="mt-4 text-sm text-gray-600">{{ __('Förhandsgranskningen genomfördes aldrig och har rensats. Gör en ny prisuppdatering.') }}</p>
        @endif
    </x-ui.card>

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            <label for="preview_show" class="text-sm text-gray-700">{{ __('Visa') }}</label>
            <select id="preview_show" class="{{ $input }} max-w-56" wire:model.live="show">
                <option value="alla">{{ __('Alla priser') }}</option>
                <option value="andras">{{ $isPreview ? __('Priser som ändras') : __('Priser som ändrades') }}</option>
                <option value="fel">{{ __('Priser som inte kan ändras') }}</option>
                <option value="oforandrade">{{ __('Oförändrade och utelämnade') }}</option>
            </select>
            <a href="{{ route('pricing.updates') }}" class="text-sm font-medium text-accent-700 underline">{{ __('Alla prisuppdateringar') }}</a>
        </div>

        @if($lines->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga priser att visa')">{{ __('Välj ett annat filter.') }}</x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ __('Priser i prisuppdateringen') }}</caption>
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            @if($canManage && $isPreview)
                                <th scope="col" class="py-2 pr-3 font-medium">{{ __('Med') }}</th>
                            @endif
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Pris') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Gammalt') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Nytt') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Ändring') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Från') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Status och fel') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($lines as $line)
                            @php
                                $currency = $line->target === PriceUpdateTarget::List ? (string) $line->priceList?->currency : 'SEK';
                                $change = $line->changeBp();
                                $left = in_array($line->id, $this->excluded, true);
                            @endphp
                            <tr wire:key="line-{{ $line->id }}" class="{{ $left ? 'opacity-50' : '' }}">
                                @if($canManage && $isPreview)
                                    <td class="py-2 pr-3">
                                        @if($line->status === PriceUpdateLineStatus::Ready)
                                            <input type="checkbox" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" @checked(! $left) wire:click="toggle({{ $line->id }})" aria-label="{{ __('Ta med :article', ['article' => $line->article?->number]) }}">
                                        @endif
                                    </td>
                                @endif
                                <td class="py-2 pr-4">
                                    <span class="tabular-nums font-medium text-gray-900">{{ $line->article?->number ?? $line->article_number }}</span>
                                    <span class="block text-xs text-gray-500">{{ $line->article?->name }}@if($line->position > 0 && $run->kind === App\Domain\Pricing\PriceUpdateKind::File) · {{ __('rad :row', ['row' => $line->position]) }}@endif</span>
                                </td>
                                <td class="py-2 pr-4 text-gray-700">
                                    {{ $line->target === PriceUpdateTarget::Base ? __('Grundpris') : $line->priceList?->code }}
                                    @if($line->unit || $line->min_quantity_milli > 0)
                                        <span class="block text-xs text-gray-500">{{ $line->unit?->code }}@if($line->min_quantity_milli > 0) {{ __('från :quantity', ['quantity' => str_replace('.', ',', App\Modules\Inventory\Domain\Quantity::decimal($line->min_quantity_milli))]) }}@endif</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums text-gray-700">{{ $price($line->old_minor, $line->old_discount_bp, $currency) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums font-medium text-gray-900">{{ $line->status === PriceUpdateLineStatus::Refused ? '–' : $price($line->new_minor, $line->new_discount_bp, $currency) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums {{ $change !== null && $change < 0 ? 'text-red-700' : 'text-gray-700' }}">{{ $change === null || $line->status === PriceUpdateLineStatus::Refused ? '' : ($change > 0 ? '+' : '').PriceInput::basisPointsToPercent($change).' %' }}</td>
                                <td class="py-2 pr-4 tabular-nums text-gray-700">{{ $line->valid_from->toDateString() }}</td>
                                <td class="py-2">
                                    <x-ui.badge :tone="match ($line->status) { PriceUpdateLineStatus::Refused, PriceUpdateLineStatus::Skipped => 'danger', PriceUpdateLineStatus::Applied => 'success', PriceUpdateLineStatus::Ready => 'accent', default => 'neutral' }">{{ $left ? PriceUpdateLineStatus::Excluded->label() : $line->status->label() }}</x-ui.badge>
                                    @if($line->reason)
                                        <span class="block text-xs text-red-700">{{ $line->reason->label() }}</span>
                                    @endif
                                    @foreach(in_array($line->status, [PriceUpdateLineStatus::Ready, PriceUpdateLineStatus::Applied], true) ? ($line->flags ?? []) : [] as $flag)
                                        <span class="block text-xs text-amber-800">{{ str_starts_with($flag, 'below_target:') ? __('Under målet: :percent % täckningsgrad', ['percent' => PriceInput::basisPointsToPercent((int) substr($flag, 13))]) : ($flagLabels[$flag] ?? $flag) }}</span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $lines->links() }}</div>
        @endif
    </x-ui.card>
</div>
