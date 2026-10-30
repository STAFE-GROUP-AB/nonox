{{-- NONOX AI: the analyse button and what the AI could not settle, beside the document. --}}
@if($aiAvailable ?? false)
    <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900">{{ __('NONOX AI') }}</p>
                <p class="text-xs text-gray-600">{{ __('Läser underlaget och fyller i ett förslag. Inget sparas förrän du gör det.') }}</p>
            </div>
            <button type="button" wire:click="analyze" wire:loading.attr="disabled" wire:target="analyze"
                class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 disabled:cursor-wait disabled:opacity-60">
                <span wire:loading.remove wire:target="analyze">{{ __('Analysera') }}</span>
                <span wire:loading wire:target="analyze">{{ __('Analyserar…') }}</span>
            </button>
        </div>

        @unless($canAnalyze)
            <p class="mt-2 text-xs text-gray-600">{{ __('Dra in underlaget som PDF eller bild ovanför, och klicka sedan Analysera.') }}</p>
        @endunless

        @if($aiResult)
            <div class="mt-3 space-y-1 border-t border-gray-200 pt-3 text-sm" role="status">
                <p class="font-medium text-gray-900">{{ __('Förslaget är ifyllt. Kontrollera varje uppgift mot underlaget innan du sparar.') }}</p>
                @if(isset($aiResult['confidence']))
                    <p class="text-gray-600">{{ __('Säkerhet: :percent %', ['percent' => (int) round($aiResult['confidence'] * 100)]) }}</p>
                @endif
                @if(! empty($aiResult['supplier']))
                    <p class="text-accent-800">{{ __('Leverantören finns inte i registret: :supplier. Lägg till den under Leverantörer och välj den sedan.', ['supplier' => $aiResult['supplier']]) }}</p>
                @endif
                @if(! empty($aiResult['supplier_invoice']))
                    <div class="mt-2 rounded-lg border border-accent-200 bg-white p-3">
                        <p class="font-medium text-gray-900">{{ __('Det här ser ut som en obetald leverantörsfaktura.') }}</p>
                        <p class="mt-1 text-gray-700">{{ __('Registrera den som leverantörsfaktura i stället, så får den förfallodag, betalning och leverantör, och leverantören kan skapas direkt. Bokför den bara här om den redan är betald.') }}</p>
                        <button type="button" wire:click="toSupplierInvoice" class="mt-2 inline-flex rounded-lg bg-accent-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Registrera som leverantörsfaktura') }}</button>
                    </div>
                @endif
                @if(! empty($aiResult['notes']))
                    <p class="text-gray-700">{{ $aiResult['notes'] }}</p>
                @endif
            </div>
        @endif
    </div>
@endif
