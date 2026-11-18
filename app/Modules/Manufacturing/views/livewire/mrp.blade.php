@php
    use App\Modules\Inventory\Domain\Quantity;
    use App\Modules\Manufacturing\Mrp\DemandKind;
    use App\Modules\Manufacturing\Mrp\Models\MrpPlannedOrder;
    use App\Modules\Manufacturing\Mrp\PlannedOrderException;
    use App\Modules\Manufacturing\Mrp\PlannedOrderKind;
    use App\Modules\Manufacturing\Mrp\SupplyMessageKind;
    use App\Modules\Manufacturing\Mrp\SupplySource;
    use Carbon\CarbonImmutable;

    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $articleLabel = fn ($article): string => $article === null ? __('Okänd artikel') : "{$article->number} {$article->name}";
    $week = fn (string $date): string => __('v.').CarbonImmutable::parse($date)->isoWeek();
@endphp
<div class="space-y-6">
    <x-ui.card :description="__('Behovsplaneringen räknar vecka för vecka vad varje artikel med planeringsmetoden Behovsplanering (MRP) behöver: det största av prognosen och kundordrarna, plus komponenter till tillverkning. Det som redan finns i lager eller är beställt räknas av. Resultatet är förslag som du själv gör om till utkast.')">
        @if($canRun)
            <form wire:submit="run" class="flex flex-wrap items-end gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">{{ __('Antal veckor (högst :max)', ['max' => $maxWeeks]) }}</span>
                    <input type="number" min="1" max="{{ $maxWeeks }}" wire:model.blur="weeks" class="{{ $input }} w-28 text-right tabular-nums">
                </label>
                <x-button type="submit" wire:loading.attr="disabled">{{ __('Kör behovsplanering') }}</x-button>
            </form>
        @endif

        @if($run === null)
            <div class="mt-6">
                <x-ui.empty-state :title="__('Ingen behovsplanering ännu')">
                    {{ __('Sätt planeringsmetoden Behovsplanering (MRP) på artiklarna på fliken Avancerad i artikelregistret, lägg gärna en prognos under Lager → Prognos, och kör sedan behovsplaneringen här.') }}
                </x-ui.empty-state>
            </div>
        @else
            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-5">
                <div><dt class="text-gray-600">{{ __('Senast körd') }}</dt><dd class="font-medium tabular-nums">{{ $run->created_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i') }}@if($run->runner) <span class="block font-normal text-gray-600">{{ $run->runner->name }}</span>@endif</dd></div>
                <div><dt class="text-gray-600">{{ __('Period') }}</dt><dd class="font-medium tabular-nums">{{ __(':weeks veckor från :from', ['weeks' => $run->horizon_weeks, 'from' => $run->starts_on->toDateString()]) }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Planerade artiklar') }}</dt><dd class="font-medium tabular-nums">{{ $run->article_count }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Planerade order') }}</dt><dd class="font-medium tabular-nums">{{ $run->planned_order_count }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Att åtgärda') }}</dt><dd class="font-medium tabular-nums">{{ $run->exception_count }}</dd></div>
            </dl>
        @endif
    </x-ui.card>

    @if($run !== null)
        <x-ui.card data-testid="mrp-planned" :title="__('Planerade order')" :description="__('Varje rad är ett förslag: vad som behöver köpas eller tillverkas, när det måste beställas eller startas och när det behövs. Öppna Täcker för att se vilka behov raden täcker.')">
            <div class="mb-4 flex flex-wrap items-end gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                    <select wire:model.live="kind" class="{{ $input }}">
                        <option value="">{{ __('Inköp och tillverkning') }}</option>
                        <option value="inkop">{{ __('Bara inköp') }}</option>
                        <option value="tillverkning">{{ __('Bara tillverkning') }}</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 pb-2">
                    <input type="checkbox" wire:model.live="exceptionsOnly" class="rounded-sm border-stone-300 text-ink focus:ring-accent-500">
                    <span class="text-sm text-gray-700">{{ __('Bara de som ska åtgärdas') }}</span>
                </label>
            </div>

            @if($orders === null || $orders->isEmpty())
                <x-ui.empty-state :title="__('Inga planerade order')">
                    {{ __('Allt som behövs under perioden täcks av lagret och det som redan är beställt.') }}
                </x-ui.empty-state>
            @else
                <form wire:submit="convert" class="space-y-4">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-stone-200 text-left text-gray-600">
                                    @if($canRun)<th scope="col" class="py-2 pr-3 font-medium"><span class="sr-only">{{ __('Välj') }}</span></th>@endif
                                    <th scope="col" class="py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">{{ __('Typ') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Antal') }}</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">{{ __('Beställ eller starta') }}</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">{{ __('Behövs') }}</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">{{ __('Leverantör') }}</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">{{ __('Råd') }}</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">{{ __('Täcker') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach($orders as $order)
                                    @php($stock = $order->article?->stockUnitCode() ?? '')
                                    <tr wire:key="planned-{{ $order->id }}" class="align-top">
                                        @if($canRun)
                                            <td class="py-2 pr-3">
                                                @unless($order->isConverted())
                                                    <input type="checkbox" value="{{ $order->id }}" wire:model="selected" aria-label="{{ __('Välj :article', ['article' => $articleLabel($order->article)]) }}" class="rounded border-gray-300 text-accent-600">
                                                @endunless
                                            </td>
                                        @endif
                                        <td class="py-2 pr-4">{{ $articleLabel($order->article) }}</td>
                                        <td class="py-2 pr-4">{{ $order->kind->label() }}</td>
                                        <td class="whitespace-nowrap py-2 pr-4 text-right tabular-nums">{{ Quantity::format($order->quantity_milli) }} {{ $stock }}</td>
                                        <td class="whitespace-nowrap py-2 pr-4 tabular-nums">{{ $order->release_on->toDateString() }}</td>
                                        <td class="whitespace-nowrap py-2 pr-4 tabular-nums">{{ $order->due_on->toDateString() }} <span class="text-gray-600">{{ $week($order->due_on->toDateString()) }}</span></td>
                                        <td class="py-2 pr-4">
                                            @if($order->kind === PlannedOrderKind::Purchase)
                                                {{ $order->supplier?->name ?? __('Ingen leverantör') }}
                                            @else
                                                <span class="text-gray-600">–</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4">
                                            @if($order->isConverted())
                                                @if($order->converted_type === MrpPlannedOrder::PURCHASE_ORDER && $purchaseOrders->has($order->converted_id))
                                                    <x-ui.badge tone="success">{{ __('Utkast') }}</x-ui.badge>
                                                    <a href="{{ route('inventory.purchaseOrders.show', $order->converted_id) }}" wire:navigate class="ms-1 text-accent-700 underline">{{ __('Inköpsorder :number', ['number' => $purchaseOrders->get($order->converted_id)->number]) }}</a>
                                                @elseif($order->converted_type === MrpPlannedOrder::PRODUCTION_ORDER && $productionOrders->has($order->converted_id))
                                                    <x-ui.badge tone="success">{{ __('Utkast') }}</x-ui.badge>
                                                    <a href="{{ route('manufacturing.productionOrders.show', $order->converted_id) }}" wire:navigate class="ms-1 text-accent-700 underline">{{ $productionOrders->get($order->converted_id)->reference() }}</a>
                                                @else
                                                    <x-ui.badge tone="success">{{ __('Utkast skapat') }}</x-ui.badge>
                                                @endif
                                            @elseif($order->exception === PlannedOrderException::Late)
                                                <x-ui.badge tone="danger">{{ $order->exception->label() }}</x-ui.badge>
                                                <span class="mt-1 block text-gray-600">{{ __('Beställningsdagen har passerat. Med ledtiden kommer det tidigast :date.', ['date' => $order->release_on->addDays((int) $order->article?->lead_time_days)->toDateString()]) }}</span>
                                            @elseif($order->exception === PlannedOrderException::ReleaseNow)
                                                <x-ui.badge tone="warning">{{ $order->exception->label() }}</x-ui.badge>
                                            @else
                                                <span class="text-gray-600">–</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4">
                                            <details>
                                                <summary class="cursor-pointer text-accent-700">{{ __(':count behov', ['count' => count($order->pegging)]) }}</summary>
                                                <ul class="mt-2 space-y-1 text-gray-700">
                                                    @foreach($order->pegging as $peg)
                                                        @php($kind = DemandKind::tryFrom((string) $peg['kind']))
                                                        <li class="tabular-nums">
                                                            @switch($kind)
                                                                @case(DemandKind::CustomerOrder)
                                                                    {{ __('Kundorder :number, :date', ['number' => $peg['number'], 'date' => $peg['on']]) }}
                                                                    @break
                                                                @case(DemandKind::Forecast)
                                                                    {{ __('Prognos :week utöver kundordrarna', ['week' => $week((string) $peg['on'])]) }}
                                                                    @break
                                                                @case(DemandKind::ProductionOrder)
                                                                    {{ __('Tillverkningsorder TO-:number, :date', ['number' => $peg['number'], 'date' => $peg['on']]) }}
                                                                    @break
                                                                @case(DemandKind::PlannedParent)
                                                                    {{ __('Planerad tillverkning av :article, :date', ['article' => $articleLabel($articles->get((int) $peg['article_id'])), 'date' => $peg['on']]) }}
                                                                    @break
                                                                @default
                                                                    {{ __('Påfyllning till säkerhetslagret') }}
                                                            @endswitch
                                                            <span class="text-gray-600">· {{ Quantity::format((int) $peg['quantity_milli']) }} {{ $stock }}</span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div>{{ $orders->links() }}</div>
                    @if($canRun)
                        <div class="flex flex-wrap items-center gap-3">
                            <x-button type="submit" wire:loading.attr="disabled" wire:confirm="{{ __('Skapa utkast av de valda planerade orderna? Inköp blir ett utkast till inköpsorder per leverantör och tillverkning ett utkast till tillverkningsorder. Inget skickas eller släpps.') }}">{{ __('Skapa utkast av de valda') }}</x-button>
                            <span class="text-sm text-gray-600">
                                @if(! $canPurchase && $orders->getCollection()->contains(fn ($order) => $order->kind === PlannedOrderKind::Purchase))
                                    {{ __('Du kan inte skapa inköpsorder, så välj bara tillverkning.') }}
                                @elseif(! $canProduce && $orders->getCollection()->contains(fn ($order) => $order->kind === PlannedOrderKind::Production))
                                    {{ __('Du kan inte skapa tillverkningsorder, så välj bara inköp.') }}
                                @else
                                    {{ __('Ett utkast per leverantör för inköp och ett per order för tillverkning. Inget skickas eller släpps.') }}
                                @endif
                            </span>
                        </div>
                    @endif
                </form>
            @endif
        </x-ui.card>

        @if($run->messages !== [])
            <x-ui.card :title="__('Råd om befintliga order')" :description="__('Order som redan finns men behövs vid en annan tid, eller inte behövs fullt ut under perioden. Ändra dem på inköps- eller tillverkningsordern om du håller med.')">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 text-left text-gray-600">
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Råd') }}</th>
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Order') }}</th>
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Antal') }}</th>
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Väntas') }}</th>
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Behövs') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($run->messages as $index => $message)
                                @php($kind = SupplyMessageKind::tryFrom((string) $message['kind']))
                                @php($source = SupplySource::tryFrom((string) $message['source']))
                                @php($article = $articles->get((int) $message['article_id']))
                                <tr wire:key="message-{{ $index }}">
                                    <td class="py-2 pr-4"><x-ui.badge :tone="$kind === SupplyMessageKind::MoveIn ? 'warning' : 'neutral'">{{ $kind?->label() }}</x-ui.badge></td>
                                    <td class="py-2 pr-4">
                                        @if($source === SupplySource::PurchaseOrder)
                                            <a href="{{ route('inventory.purchaseOrders.show', (int) $message['id']) }}" wire:navigate class="text-accent-700 underline">{{ __('Inköpsorder :number', ['number' => $message['number']]) }}</a>
                                        @else
                                            <a href="{{ route('manufacturing.productionOrders.show', (int) $message['id']) }}" wire:navigate class="text-accent-700 underline">{{ __('Tillverkningsorder TO-:number', ['number' => $message['number']]) }}</a>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">{{ $articleLabel($article) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ Quantity::format((int) $message['quantity_milli']) }} {{ $article?->stockUnitCode() }}@if((int) ($message['order_milli'] ?? $message['quantity_milli']) > (int) $message['quantity_milli'])<span class="block text-gray-600">{{ __('av :quantity på ordern', ['quantity' => Quantity::format((int) $message['order_milli'])]) }}</span>@endif</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $message['on'] }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $kind === SupplyMessageKind::MoveIn ? $message['to'] : __('Inte under perioden') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    @endif
</div>
