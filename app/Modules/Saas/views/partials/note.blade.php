{{-- SA-R0: on every page. --}}
<p class="text-sm text-gray-600">
    {{ __('Nyckeltal för styrning, inte bokföring. MRR är det avtalade återkommande beloppet utan moms vid månadens sista dag, omräknat till en månad.') }}
    @if(! empty($historyFrom))
        {{ __('Siffrorna är kompletta från :month, då modulen slogs på; tidigare månader är uppskattade ur utställda avtalsfakturor.', ['month' => \App\Modules\Saas\Domain\Months::label($historyFrom)]) }}
    @endif
</p>
