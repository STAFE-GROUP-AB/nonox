{{-- The cost per unit of a bill of materials or a row copy (ADR-071 §6): material, labour, overhead and their sum. --}}
@php($money = fn (int $ore) => App\Support\Money\Money::ofOre($ore))
<dl class="mt-6 grid gap-3 sm:grid-cols-4">
    <div class="rounded-2xl bg-stone-50 p-4">
        <dt class="text-sm text-gray-500">{{ __('Material per :unit', ['unit' => $unit]) }}</dt>
        <dd class="mt-1 text-xl font-bold"><x-ui.amount :money="$money($costing->materialPerUnitOre)" /></dd>
    </div>
    <div class="rounded-2xl bg-stone-50 p-4">
        <dt class="text-sm text-gray-500">{{ __('Arbete per :unit', ['unit' => $unit]) }}</dt>
        <dd class="mt-1 text-xl font-bold"><x-ui.amount :money="$money($costing->labourPerUnitOre)" /></dd>
        <dd class="text-xs text-gray-500">{{ __(':time för hela antalet', ['time' => App\Modules\Manufacturing\Livewire\BillOfMaterialsPage::duration($costing->minutes())]) }}</dd>
    </div>
    <div class="rounded-2xl bg-stone-50 p-4">
        <dt class="text-sm text-gray-500">{{ __('Omkostnader per :unit', ['unit' => $unit]) }}</dt>
        <dd class="mt-1 text-xl font-bold"><x-ui.amount :money="$money($costing->overheadPerUnitOre)" /></dd>
        <dd class="text-xs text-gray-500">{{ $costing->overheadBp > 0 ? __('Pålägg :percent', ['percent' => App\Modules\Manufacturing\Livewire\BillOfMaterialsPage::percent($costing->overheadBp)]) : __('Inget pålägg') }}</dd>
    </div>
    <div class="rounded-2xl bg-stone-50 p-4">
        <dt class="text-sm text-gray-500">{{ __('Kostnad per :unit', ['unit' => $unit]) }}</dt>
        <dd class="mt-1 text-xl font-bold">@if($costing->complete)<x-ui.amount :money="$money($costing->perUnitOre())" />@else<span class="text-accent-700">{{ __('Ofullständig – en komponent saknar pris') }}</span>@endif</dd>
    </div>
</dl>
