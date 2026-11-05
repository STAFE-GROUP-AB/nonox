@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kr = fn (int $kronor): string => number_format($kronor, 0, ',', "\u{a0}")."\u{a0}kr";
@endphp
<div class="space-y-6">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Välj räkenskapsår') }}">
        @foreach($years as $option)
            <button type="button" wire:key="year-{{ $option->id }}" wire:click="$set('year', {{ $option->id }})" @class([
                'rounded-full px-4 py-2 text-sm font-semibold transition',
                'bg-ink text-white' => $option->id === $fiscalYear->id,
                'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => $option->id !== $fiscalYear->id,
            ]) @if($option->id === $fiscalYear->id) aria-current="true" @endif>{{ $option->label() }}</button>
        @endforeach
    </nav>

    @switch($form)
        @case('INK2')
            @include('livewire.accounting.income-tax.ink2')
            @break
        @case('NE')
            @include('livewire.accounting.income-tax.ne')
            @break
        @case('INK4')
            @include('livewire.accounting.income-tax.ink4')
            @break
        @default
            <x-ui.empty-state :title="__('Inte för den här företagsformen')">{{ __('NONOX tar fram Inkomstdeklaration 2 för aktiebolag och ekonomiska föreningar, NE-bilagan för enskild firma och Inkomstdeklaration 4 för handelsbolag och kommanditbolag. En ideell förening eller stiftelse deklarerar i Skatteverkets e-tjänst.') }}</x-ui.empty-state>
    @endswitch
</div>
