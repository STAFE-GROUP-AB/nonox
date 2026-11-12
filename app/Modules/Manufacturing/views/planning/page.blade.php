@php
    $titles = [
        'timeline' => [__('Planering'), __('Tidslinje över ordrarna i verkstaden: arbetsstegen som staplar, utlovat datum som markering och prognosen där staplarna slutar.')],
        'resources' => [__('Resurser'), __('Personer och arbetsstationer per dag: arbetstid, bokat arbete och frånvaro.')],
        'calendar' => [__('Leveranskalender'), __('Beräknade färdig- och leveransdatum per dag, vecka eller månad.')],
        'orders' => [__('Ordrar i planeringen'), __('Utlovat datum mot prognos, skillnad i arbetsdagar och vad varje order väntar på.')],
        'deviations' => [__('Avvikelser'), __('Det som har flyttat planen, vad det gör med leveranserna och förslag på vad som kan hjälpa.')],
        'scenario' => [__('Tänk om'), __('Pröva ändringar utan att röra planen: övertid, flytta eller dela arbete, ändra prioritet.')],
        'setup' => [__('Inställningar för planering'), __('Arbetsstationer, personer och kompetenser, arbetstider och hur långt fram planen räknar.')],
    ];
@endphp
<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="$titles[$screen][0]" :lead="$titles[$screen][1]" />
        @include('manufacturing::planning.tabs')
        @livewire('manufacturing.planning.'.$screen)
    </div>
</x-app-layout>
