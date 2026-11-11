@php($input = 'mt-1 block w-full rounded-xl border-gray-300 py-3 text-base focus:border-accent-500 focus:ring-accent-500')
@php($big = 'flex min-h-12 items-center justify-center rounded-xl px-4 text-base font-semibold')
@php($hours = fn (int $minutes): string => rtrim(rtrim(number_format($minutes / 60, 2, ',', ''), '0'), ','))
<div class="space-y-4">
    <div>
        <a href="{{ route('workOrders.mine') }}" class="text-sm text-accent-700">← {{ __('Mina arbetsorder') }}</a>
        <h1 class="mt-1 text-xl font-semibold text-gray-900">{{ $workOrder->title }}</h1>
        <p class="text-sm text-gray-600">{{ __('Arbetsorder :number', ['number' => $workOrder->number]) }} · {{ $workOrder->customer?->name }}</p>
        @if($workOrder->site_address_line1)<p class="text-sm text-gray-600">{{ $workOrder->site_address_line1 }}@if($workOrder->site_city), {{ $workOrder->site_city }}@endif</p>@endif
        @if($workOrder->access_notes)<p class="mt-2 rounded-lg bg-stone-100 px-3 py-2 text-sm text-gray-800">{{ __('Portkod och nycklar: :notes', ['notes' => $workOrder->access_notes]) }}</p>@endif
    </div>

    @if($heldBack !== null)
        <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900" role="alert">{{ __(':ata är inte godkänt. Kunden ska godkänna priset innan arbetet görs; tiden hålls tillbaka från fakturan tills dess.', ['ata' => $heldBack->label()]) }}</p>
    @endif

    <nav class="grid grid-cols-4 gap-2" aria-label="{{ __('Vad vill du göra?') }}">
        @foreach(['tid' => __('Tid'), 'material' => __('Material'), 'foto' => __('Foto'), 'ata' => __('ÄTA')] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([$big, 'bg-accent-600 text-white' => $tab === $key, 'bg-white text-gray-800 border border-stone-200' => $tab !== $key]) aria-pressed="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}</button>
        @endforeach
    </nav>

    @if($tab === 'tid')
        <form wire:submit="recordTime" class="space-y-4 rounded-xl bg-white p-4 shadow-xs">
            <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Typ av arbete') }}</span>
                <select wire:model.live="time.labour_kind_id" class="{{ $input }}">@foreach($kinds as $kind)<option value="{{ $kind->id }}">{{ $kind->name }}</option>@endforeach</select>
            </label>
            @if(optional($kinds->firstWhere('id', (int) $time['labour_kind_id']))->unit?->isTime() ?? true)
                <div>
                    <span class="text-sm font-medium text-gray-700">{{ __('Timmar') }}</span>
                    <div class="mt-1 grid grid-cols-[auto_1fr_auto] items-center gap-2">
                        <button type="button" wire:click="step(-15)" class="{{ $big }} border border-stone-300 bg-white" aria-label="{{ __('15 minuter mindre') }}">−</button>
                        <input wire:model="time.hours" inputmode="decimal" placeholder="0" class="{{ $input }} text-center text-2xl tabular-nums" aria-label="{{ __('Timmar') }}">
                        <button type="button" wire:click="step(15)" class="{{ $big }} border border-stone-300 bg-white" aria-label="{{ __('15 minuter mer') }}">+</button>
                    </div>
                    @error('time.hours')<p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
                </div>
            @else
                <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Antal') }}</span><input wire:model="time.quantity" inputmode="decimal" class="{{ $input }}"></label>
                @error('time.quantity')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
            @endif
            <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Del') }}</span>
                <select wire:model.live="time.change_order_id" class="{{ $input }}">
                    <option value="">{{ __('Huvudarbete') }}</option>
                    @foreach($changeOrders as $changeOrder)<option value="{{ $changeOrder->id }}">{{ $changeOrder->label() }} {{ $changeOrder->title }}</option>@endforeach
                </select>
            </label>
            <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Anteckning') }}</span><input wire:model="time.note" maxlength="255" class="{{ $input }}"></label>
            <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Datum') }}</span><input type="date" wire:model="time.work_date" class="{{ $input }}"></label>
            <button type="submit" wire:loading.attr="disabled" class="{{ $big }} w-full bg-accent-600 text-white">{{ __('Spara tid') }}</button>
        </form>
    @elseif($tab === 'material')
        <form wire:submit="recordMaterial" class="space-y-4 rounded-xl bg-white p-4 shadow-xs">
            <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Sök artikel, eller skanna koden') }}</span>
                <input type="search" wire:model.live.debounce.300ms="articleSearch" inputmode="search" class="{{ $input }}" autocomplete="off">
            </label>
            @if($articles->isNotEmpty())
                <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200">
                    @foreach($articles as $article)
                        <li><button type="button" wire:click="chooseArticle({{ $article->id }})" class="block min-h-12 w-full px-3 py-2 text-left">{{ $article->name }} <span class="text-sm text-gray-500">{{ $article->number }}</span></button></li>
                    @endforeach
                </ul>
            @endif
            <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Material') }}</span><input wire:model="material.description" maxlength="255" class="{{ $input }}"></label>
            @error('material.description')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Antal') }}</span><input wire:model="material.quantity" inputmode="decimal" class="{{ $input }}"></label>
                <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Enhet') }}</span>
                    <select wire:model="material.unit_id" class="{{ $input }}">
                        @foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->code }} ({{ $unit->name }})</option>@endforeach
                    </select>
                </label>
            </div>
            @error('material.quantity')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
            @error('material.unit_id')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
            @if($locations->isNotEmpty() && ($material['article_id'] ?? '') !== '')
                <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Taget från lager (valfritt)') }}</span>
                    <select wire:model="material.location_id" class="{{ $input }}">
                        <option value="">{{ __('Inte från lagret') }}</option>
                        @foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->full_code }} {{ $location->name }}</option>@endforeach
                    </select>
                </label>
            @endif
            <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Del') }}</span>
                <select wire:model.live="material.change_order_id" class="{{ $input }}">
                    <option value="">{{ __('Huvudarbete') }}</option>
                    @foreach($changeOrders as $changeOrder)<option value="{{ $changeOrder->id }}">{{ $changeOrder->label() }} {{ $changeOrder->title }}</option>@endforeach
                </select>
            </label>
            <button type="submit" wire:loading.attr="disabled" class="{{ $big }} w-full bg-accent-600 text-white">{{ __('Spara material') }}</button>
        </form>
    @elseif($tab === 'foto')
        <form wire:submit="uploadPhoto" class="space-y-4 rounded-xl bg-white p-4 shadow-xs">
            <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Ta eller välj en bild') }}</span>
                <input type="file" wire:model="photo" accept="image/*" capture="environment" class="mt-2 block w-full text-base">
            </label>
            @error('photo')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
            <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Bildtext') }}</span><input wire:model="photoCaption" maxlength="255" class="{{ $input }}"></label>
            <p class="text-xs text-gray-500">{{ __('Fotografera inte personer i onödan. Bilderna sparas med arbetsordern och syns bara för den som får se den.') }}</p>
            <button type="submit" wire:loading.attr="disabled" class="{{ $big }} w-full bg-accent-600 text-white">{{ __('Spara bilden') }}</button>
            @if($photos->isNotEmpty())
                <div class="grid grid-cols-3 gap-2">
                    @foreach($photos as $each)
                        <a href="{{ route('workOrders.photo', [$workOrder->id, $each->id]) }}" target="_blank" rel="noopener" class="block truncate rounded-lg bg-stone-100 px-2 py-3 text-center text-xs text-gray-700">{{ $each->caption ?: __('Bild :number', ['number' => $each->id]) }}</a>
                    @endforeach
                </div>
            @endif
        </form>
    @else
        <form wire:submit="startChange" class="space-y-4 rounded-xl bg-white p-4 shadow-xs">
            <p class="text-sm text-gray-600">{{ __('Vill kunden ändra något, eller behövs ett tilläggsarbete? Beskriv det här. Kontoret sätter priset och kunden godkänner det innan arbetet görs.') }}</p>
            <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Vad gäller ändringen?') }}</span><input wire:model="change.title" maxlength="255" class="{{ $input }}"></label>
            @error('change.title')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
            <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Vad ska göras') }}</span><textarea wire:model="change.description" rows="4" class="{{ $input }}"></textarea></label>
            @error('change.description')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
            <button type="submit" wire:loading.attr="disabled" class="{{ $big }} w-full bg-accent-600 text-white">{{ __('Skapa ÄTA') }}</button>
        </form>
        @if($changeOrders->isNotEmpty())
            <section class="space-y-3 rounded-xl bg-white p-4 shadow-xs" aria-labelledby="ata-heading">
                <h2 id="ata-heading" class="text-base font-semibold text-gray-900">{{ __('ÄTA på jobbet') }}</h2>
                <ul class="divide-y divide-stone-100">
                    @foreach($changeOrders as $changeOrder)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="field-ata-{{ $changeOrder->id }}">
                            <span>{{ $changeOrder->label() }}: {{ $changeOrder->title }} · {{ in_array($changeOrder->id, $lapsed, true) ? App\Modules\WorkOrders\Domain\ChangeOrderStatus::Expired->label() : $changeOrder->status->label() }}</span>
                            @if($changeOrder->status === App\Modules\WorkOrders\Domain\ChangeOrderStatus::Sent && ! in_array($changeOrder->id, $lapsed, true))
                                <button type="button" wire:click="startOnSite({{ $changeOrder->id }})" class="{{ $big }} border border-accent-600 bg-white text-accent-700">{{ __('Låt kunden signera här') }}</button>
                                <p class="w-full text-xs text-gray-500">{{ __('Kunden får en kod till sin egen e-post och signerar själv i din telefon.') }}</p>
                            @elseif($changeOrder->status === App\Modules\WorkOrders\Domain\ChangeOrderStatus::Draft)
                                <p class="w-full text-xs text-gray-500">{{ __('Kontoret sätter priset och skickar ÄTA:t. Därefter kan kunden signera här.') }}</p>
                            @elseif($changeOrder->status === App\Modules\WorkOrders\Domain\ChangeOrderStatus::Expired || in_array($changeOrder->id, $lapsed, true))
                                <p class="w-full text-xs text-amber-800">{{ __('Signeringslänken har gått ut utan svar. Be kontoret göra ett nytt ÄTA om arbetet ska göras.') }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if($onSiteId !== null)
                    <form wire:submit="verifyCode" class="space-y-3">
                        <p class="text-sm text-gray-700">{{ __('Kunden har fått en kod till sin egen e-post. Låt kunden skriva in den; sedan öppnas sidan där kunden läser och signerar. Du godkänner aldrig åt kunden.') }}</p>
                        <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Kod från kundens e-post') }}</span><input wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="{{ $input }} text-center text-2xl tracking-widest"></label>
                        @error('code')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
                        <button type="submit" wire:loading.attr="disabled" class="{{ $big }} w-full bg-accent-600 text-white">{{ __('Öppna signeringen') }}</button>
                    </form>
                @endif
            </section>
        @endif
    @endif

    <section class="rounded-xl bg-white p-4 shadow-xs" aria-labelledby="today-heading">
        <h2 id="today-heading" class="text-base font-semibold text-gray-900">{{ __('Mitt idag') }}</h2>
        @if($myTime->isEmpty() && $myMaterial->isEmpty())
            <p class="mt-2 text-sm text-gray-500">{{ __('Inget registrerat idag än.') }}</p>
        @else
            <ul class="mt-2 divide-y divide-stone-100">
                @foreach($myTime as $entry)
                    <li class="flex items-center justify-between gap-3 py-2" wire:key="t-{{ $entry->id }}">
                        <span @class(['line-through text-gray-400' => $entry->isReversed()])>{{ $entry->labourKind?->name }} · {{ $entry->minutes !== null ? $hours($entry->minutes).' '.__('tim') : rtrim(rtrim(number_format(((int) $entry->quantity_milli) / 1000, 3, ',', ''), '0'), ',') }}</span>
                        @if($entry->isLive() && $entry->invoice_id === null)<button type="button" wire:click="startReverse('time:{{ $entry->id }}')" class="min-h-10 px-2 text-sm font-medium text-accent-700">{{ __('Backa') }}</button>@endif
                    </li>
                @endforeach
                @foreach($myMaterial as $entry)
                    <li class="flex items-center justify-between gap-3 py-2" wire:key="m-{{ $entry->id }}">
                        <span @class(['line-through text-gray-400' => $entry->isReversed()])>{{ $entry->description }} · {{ rtrim(rtrim(number_format($entry->quantity_milli / 1000, 3, ',', ''), '0'), ',') }} {{ $entry->unit }}</span>
                        @if($entry->isLive() && $entry->invoice_id === null)<button type="button" wire:click="startReverse('material:{{ $entry->id }}')" class="min-h-10 px-2 text-sm font-medium text-accent-700">{{ __('Backa') }}</button>@endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if($reversing !== null)
            <form wire:submit="reverse" class="mt-3 space-y-3">
                <label class="block"><span class="text-sm font-medium text-gray-700">{{ __('Varför backas posten?') }}</span><input wire:model="reason" maxlength="255" class="{{ $input }}" required></label>
                <div class="grid grid-cols-2 gap-2">
                    <button type="submit" wire:confirm="{{ __('Backa posten? Den finns kvar och en motpost läggs till.') }}" class="{{ $big }} bg-accent-600 text-white">{{ __('Backa posten') }}</button>
                    <button type="button" wire:click="$set('reversing', null)" class="{{ $big }} border border-stone-300 bg-white">{{ __('Avbryt') }}</button>
                </div>
            </form>
        @endif
    </section>
</div>
