@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-3">
    <p class="text-sm font-medium text-ink">{{ __('Omräkningar för recept') }}</p>
    <p class="text-sm text-gray-600">{{ __('Andra enheter som en receptrad får skrivas i, till exempel cl av en flaska på 70 cl. Gram och liter räknas om utan omräkning.') }}</p>
    @if($conversions->isNotEmpty())
        <ul class="divide-y divide-stone-200 text-sm">
            @foreach($conversions as $conversion)
                <li class="flex justify-between py-2" wire:key="conversion-{{ $conversion->id }}">
                    <span>{{ __('1 :article = :factor :unit', ['article' => $article->unit, 'factor' => App\Modules\Inventory\Domain\Quantity::format($conversion->factor_milli), 'unit' => $conversion->unit]) }}</span>
                    <button type="button" wire:click="remove({{ $conversion->id }})" wire:confirm="{{ __('Ta bort omräkningen? Sparade recept ändras inte.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Ta bort') }}</button>
                </li>
            @endforeach
        </ul>
    @endif
    <div class="flex flex-wrap items-end gap-3">
        <x-ui.field for="conversion-factor-{{ $article->id }}" :label="__('1 :unit är', ['unit' => $article->unit])" error="form.factor">
            <input id="conversion-factor-{{ $article->id }}" wire:model="form.factor" inputmode="decimal" class="{{ $input }} w-24 text-right tabular-nums" placeholder="70">
        </x-ui.field>
        <x-ui.field for="conversion-unit-{{ $article->id }}" :label="__('Enhet')" error="form.unit">
            <input id="conversion-unit-{{ $article->id }}" wire:model="form.unit" maxlength="10" class="{{ $input }} w-24" placeholder="cl">
        </x-ui.field>
        <x-secondary-button type="button" wire:click="add">{{ __('Lägg till omräkning') }}</x-secondary-button>
    </div>
</div>
