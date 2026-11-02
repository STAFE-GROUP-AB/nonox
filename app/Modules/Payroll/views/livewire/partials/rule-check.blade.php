<td colspan="{{ $colspan }}" class="bg-gray-50 px-4 py-3">
    <p class="text-sm font-medium text-gray-900">{{ App\Modules\Payroll\Support\RulePresenter::name($version->key) }}, {{ $version->valid_from->toDateString() }} – {{ $version->valid_to?->toDateString() }}</p>
    @if($version->source_quote)
        <p class="mt-1 text-sm text-gray-700">{{ __('Källan säger:') }} <q>{{ $version->source_quote }}</q></p>
    @endif
    <p class="mt-1 text-sm"><a href="{{ $version->source_url }}" target="_blank" rel="noopener" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Öppna källan och jämför värdet') }}</a></p>
    <label class="mt-3 flex items-start gap-2 text-sm">
        <input type="checkbox" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="confirmed">
        <span>{{ __('Jag har jämfört värdet med källan och tar ansvar för att det stämmer för vårt företag.') }}</span>
    </label>
    <x-input-error for="confirmed" class="mt-1" />
    <div class="mt-3 flex items-center gap-3">
        <x-button type="button" wire:click="verify">{{ __('Markera som kontrollerad') }}</x-button>
        <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelCheck">{{ __('Avbryt') }}</button>
    </div>
</td>
