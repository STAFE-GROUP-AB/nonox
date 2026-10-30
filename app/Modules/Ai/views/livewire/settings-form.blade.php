@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm')
<form wire:submit="save" class="space-y-5">
    <x-ui.field for="ai_provider" :label="__('Leverantör av AI')" error="provider">
        <select id="ai_provider" class="{{ $input }} mt-1" wire:model.live="provider">
            @foreach($providers as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
        </select>
    </x-ui.field>

    <div class="rounded-lg bg-gray-50 p-4 text-sm text-gray-700">
        <p class="font-medium text-gray-900">{{ __('Så skaffar du en nyckel från :provider', ['provider' => $current->label()]) }}</p>
        <ol class="mt-2 list-decimal space-y-1 pl-5">
            <li>{!! __('Öppna :link och logga in, eller skapa ett konto.', ['link' => '<a href="'.e($current->keyUrl()).'" target="_blank" rel="noopener" class="font-medium text-accent-600 underline hover:text-accent-700">'.e(parse_url($current->keyUrl(), PHP_URL_HOST)).'</a>']) !!}</li>
            <li>{{ __('Lägg in ett betalkort under fakturering. Analysen av en faktura kostar normalt några ören.') }}</li>
            <li>{{ __('Skapa en ny API-nyckel, kopiera den och klistra in den här nedanför.') }}</li>
        </ol>
        <p class="mt-2 text-xs text-gray-500">{{ __('Ett vanligt abonnemang som ChatGPT Plus, Claude Pro eller Gemini Advanced kan inte användas av andra program; det krävs en API-nyckel.') }}</p>
    </div>

    <x-ui.field for="ai_key" :label="__('API-nyckel')" error="apiKey"
        :hint="$keyEnding ? __('En nyckel som slutar på …:end är sparad. Fyll bara i fältet för att byta den.', ['end' => $keyEnding]) : __('Nyckeln kontrolleras hos leverantören när du sparar, sparas krypterad och visas aldrig igen.')">
        <input id="ai_key" type="password" autocomplete="off" class="{{ $input }} mt-1 font-mono" wire:model="apiKey">
    </x-ui.field>

    <x-ui.field for="ai_model" :label="__('Modell (frivilligt)')" error="model"
        :hint="$current->defaultModel() ? __('Tomt betyder :model.', ['model' => $current->defaultModel()]) : __('Tomt betyder leverantörens standardmodell.')">
        <input id="ai_model" type="text" class="{{ $input }} mt-1 font-mono" wire:model="model" placeholder="{{ $current->defaultModel() }}">
    </x-ui.field>

    <p class="text-xs text-gray-500">{{ __('Underlagen skickas till den valda leverantören för analys. NONOX AI fyller i ett förslag; inget sparas eller bokförs förrän du själv gör det.') }}</p>
    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
</form>
