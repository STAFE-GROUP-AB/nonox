@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm')
<form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
    <x-ui.field for="ai_provider" :label="__('Leverantör av AI')" error="provider">
        <select id="ai_provider" class="{{ $input }} mt-1" wire:model="provider">
            @foreach(App\Modules\Ai\AiModule::PROVIDERS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
    </x-ui.field>
    <x-ui.field for="ai_quality" :label="__('Kvalitet')" error="quality" :hint="__('Hög kvalitet kostar tre gånger så mycket per sida.')">
        <select id="ai_quality" class="{{ $input }} mt-1" wire:model="quality">
            <option value="standard">{{ __('Standard') }}</option>
            <option value="high">{{ __('Hög') }}</option>
        </select>
    </x-ui.field>
    <x-ui.field for="ai_key" :label="__('API-nyckel')" error="api_key" class="sm:col-span-2"
        :hint="$keyEnding ? __('En nyckel som slutar på …:end är sparad. Fyll bara i fältet för att byta den.', ['end' => $keyEnding]) : __('Nyckeln från GoZero Core, gzc_live_… Den sparas krypterad och visas aldrig igen.')">
        <input id="ai_key" type="password" autocomplete="off" class="{{ $input }} mt-1 font-mono" wire:model="apiKey" placeholder="gzc_live_…">
    </x-ui.field>
    <p class="text-xs text-gray-500 sm:col-span-2">{{ __('Underlagen skickas till GoZero Core för analys och behandlas inom EU. NONOX AI fyller i ett förslag; inget sparas eller bokförs förrän du själv gör det.') }}</p>
    <div class="sm:col-span-2"><x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button></div>
</form>
