<div>
    @if($limited ?? false)
        <x-ui.empty-state :title="__('Inga enheter ännu')">{{ __('Du ser rapporterna för de kostnadsställen och projekt du är begränsad till. Be ägaren eller en administratör att ge dig minst ett.') }}</x-ui.empty-state>
    @else
        <x-ui.empty-state :title="__('Inget räkenskapsår ännu')">{{ __('Rapporterna visas när företaget har ett räkenskapsår.') }}</x-ui.empty-state>
    @endif
</div>
