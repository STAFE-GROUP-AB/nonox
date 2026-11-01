{{-- Mina företag: the firm workspace tabs (ADR-028), each its own component. --}}
<div class="space-y-6">
    @include('livewire.company.overview-tabs')

    @if($tab === 'stangning')
        <livewire:firm.month-close :key="'firm-month-close'" />
    @else
        <livewire:firm.firm-overview :key="'firm-overview'" />
    @endif
</div>
