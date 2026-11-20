<div>
    @if($visible)
        <x-ui.card :title="__('Prisavtal')" :description="__('Kundens förhandlade priser kan kopplas till det här avtalet. Prisavtalet slutar då samma dag som avtalet, och påminnelserna kommer härifrån.')" data-crm-price-agreements>
            @if($linked->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Inget prisavtal är kopplat till avtalet.') }}</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach($linked as $priceAgreement)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="crm-price-{{ $priceAgreement->id }}">
                            <a href="{{ route('pricing.agreements.show', $priceAgreement->id) }}" class="font-medium text-accent-700 hover:underline">{{ $priceAgreement->reference() }} · {{ $priceAgreement->title }}</a>
                            <span class="flex items-center gap-2">
                                <span class="tabular-nums text-gray-600">{{ $priceAgreement->valid_from->toDateString() }} – {{ $priceAgreement->lastDay()?->toDateString() ?? __('tills vidare') }}</span>
                                <x-ui.badge :tone="$priceAgreement->status->tone()">{{ $priceAgreement->status->label() }}</x-ui.badge>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if($canManage && $customerId)
                <p class="mt-4 text-sm"><a href="{{ route('pricing.agreements', ['ny' => 1, 'kund' => $customerId]) }}" class="font-medium text-accent-700 underline">{{ __('Nytt prisavtal för kunden') }}</a></p>
            @elseif($canManage)
                <p class="mt-4 text-sm text-gray-600">{{ __('Kontot är inte kopplat till en kund i kundregistret, så det kan inte få ett prisavtal ännu.') }}</p>
            @endif
        </x-ui.card>
    @endif
</div>
