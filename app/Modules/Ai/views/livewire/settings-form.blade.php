@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm')
<form wire:submit="save" class="space-y-5">
    <div class="rounded-lg border border-gray-200 p-4 text-sm text-gray-700">
        <p class="font-medium text-gray-900">{{ __('Det här innebär det att använda NONOX AI') }}</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            <li>{{ __('Ni använder ert eget konto hos AI-leverantören och betalar leverantören direkt för varje analys. NONOX tar inget betalt och har ingen del i kostnaden.') }}</li>
            <li>{{ __('Underlaget skickas till leverantören för att läsas. Hur leverantören hanterar data från API-anrop står i leverantörens villkor; läs dem innan ni slår på modulen.') }}</li>
            <li>{{ __('Kör ni NONOX själva, på egen dator eller egen server, gäller samma sak: det är er nyckel och ert konto.') }}</li>
            <li>{{ __('AI:n fyller bara i ett förslag. Ingenting sparas eller bokförs förrän någon hos er har kontrollerat och sparat.') }}</li>
            <li>{{ __('Modellerna väljs av Laravel AI SDK, som vet vilken modell som är billigast och vilken som är bäst hos varje leverantör. Listan följer med när NONOX uppdateras, så ni får nya, billigare modeller utan att ändra något.') }}</li>
        </ul>
    </div>

    <x-ui.field for="ai_provider" :label="__('Leverantör av AI')" error="provider">
        <select id="ai_provider" class="{{ $input }} mt-1" wire:model.live="provider">
            @foreach($providers as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
        </select>
    </x-ui.field>

    <div class="rounded-lg bg-gray-50 p-4 text-sm text-gray-700">
        <p class="font-medium text-gray-900">{{ __('Så skaffar du en nyckel från :provider', ['provider' => $current->label()]) }}</p>
        <ol class="mt-2 list-decimal space-y-1 pl-5">
            <li>{!! __('Öppna :link och logga in, eller skapa ett konto.', ['link' => '<a href="'.e($current->keyUrl()).'" target="_blank" rel="noopener" class="font-medium text-accent-600 underline hover:text-accent-700">'.e(parse_url($current->keyUrl(), PHP_URL_HOST)).'</a>']) !!}</li>
            <li>{{ __('Lägg in ett betalkort under fakturering. Med den billigaste modellen kostar en faktura normalt en bråkdel av en krona.') }}</li>
            <li>{{ __('Skapa en ny API-nyckel, kopiera den och klistra in den här nedanför.') }}</li>
        </ol>
        <p class="mt-2 text-xs text-gray-500">{{ __('Ett vanligt abonnemang som ChatGPT Plus, Claude Pro eller Gemini Advanced kan inte användas av andra program; det krävs en API-nyckel.') }}</p>
    </div>

    <x-ui.field for="ai_key" :label="__('API-nyckel')" error="apiKey"
        :hint="$keyEnding ? __('En nyckel som slutar på …:end är sparad. Fyll bara i fältet för att byta den.', ['end' => $keyEnding]) : __('Nyckeln kontrolleras hos leverantören när du sparar, sparas krypterad och visas aldrig igen.')">
        <input id="ai_key" type="password" autocomplete="off" class="{{ $input }} mt-1 font-mono" wire:model="apiKey">
    </x-ui.field>

    <fieldset>
        <legend class="text-sm font-medium text-gray-700">{{ __('Modell') }}</legend>
        <div class="mt-2 space-y-2 text-sm">
            <label class="flex items-start gap-2">
                <input type="radio" value="CHEAPEST" wire:model.live="modelChoice" class="mt-0.5 border-gray-300 text-accent-600 focus:ring-accent-500">
                <span><span class="font-medium text-gray-900">{{ __('Billigaste lämpliga modellen (rekommenderas)') }}</span>
                    <span class="block text-xs text-gray-600">{{ __('Räcker för fakturor och kvitton. Just nu :model.', ['model' => $models['CHEAPEST']]) }}</span></span>
            </label>
            <label class="flex items-start gap-2">
                <input type="radio" value="SMARTEST" wire:model.live="modelChoice" class="mt-0.5 border-gray-300 text-accent-600 focus:ring-accent-500">
                <span><span class="font-medium text-gray-900">{{ __('Den bästa modellen') }}</span>
                    <span class="block text-xs text-gray-600">{{ __('För svårlästa underlag. Kostar mer per analys. Just nu :model.', ['model' => $models['SMARTEST']]) }}</span></span>
            </label>
            <label class="flex items-start gap-2">
                <input type="radio" value="CUSTOM" wire:model.live="modelChoice" class="mt-0.5 border-gray-300 text-accent-600 focus:ring-accent-500">
                <span class="font-medium text-gray-900">{{ __('En modell jag väljer själv') }}</span>
            </label>
            @if($modelChoice === 'CUSTOM')
                <x-ui.field for="ai_model" :label="__('Modellens namn')" error="model" class="pl-6">
                    <input id="ai_model" type="text" class="{{ $input }} mt-1 font-mono" wire:model="model" placeholder="{{ $models['CHEAPEST'] }}">
                </x-ui.field>
            @endif
        </div>
    </fieldset>

    <p class="text-xs text-gray-500">{{ __('Underlagen skickas till den valda leverantören för analys. NONOX AI fyller i ett förslag; inget sparas eller bokförs förrän du själv gör det.') }}</p>
    <label class="flex items-start gap-3 rounded-xl bg-gray-50 p-4 text-sm text-gray-800">
        <x-checkbox wire:model="readEmployeeReceipts" class="mt-0.5" />
        <span>{{ __('Låt AI läsa de anställdas kvitton för utlägg') }}
            <span class="mt-1 block text-gray-600">{{ __('Kvittona skickas då till AI-leverantören, som kan finnas utanför EU. Företaget ansvarar för att informera de anställda; det står också på deras Min sida. Utan det här valet granskar ni kvittona själva.') }}</span></span>
    </label>

    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
</form>
