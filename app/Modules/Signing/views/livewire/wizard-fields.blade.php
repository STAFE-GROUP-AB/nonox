@php
    // The field editor (ADR-046 amendment, gap 2, package S8): the document's pages with boxes over them. The
    // boxes are drawn here from the wizard's state (percent of the page), the pages by PDF.js (signing-editor.js)
    // into hosts that Livewire leaves alone. Everything can also be done with the form and the buttons beside the
    // pages, so nothing depends on dragging (WCAG 2.2, 2.5.7) or on JavaScript.
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $button = 'min-h-11 rounded-xl bg-white px-4 text-sm font-medium ring-1 ring-stone-200 hover:bg-gray-50 sm:min-h-0 sm:py-2';
    $tones = [
        'border-sky-700 bg-sky-100 text-sky-950',
        'border-emerald-700 bg-emerald-100 text-emerald-950',
        'border-amber-700 bg-amber-100 text-amber-950',
        'border-fuchsia-700 bg-fuchsia-100 text-fuchsia-950',
        'border-teal-700 bg-teal-100 text-teal-950',
        'border-rose-700 bg-rose-100 text-rose-950',
    ];
    $presetLabels = [
        'top-left' => __('Uppe till vänster'), 'top-center' => __('Uppe i mitten'), 'top-right' => __('Uppe till höger'),
        'middle-left' => __('Mitten till vänster'), 'middle-center' => __('Mitt på sidan'), 'middle-right' => __('Mitten till höger'),
        'bottom-left' => __('Nere till vänster'), 'bottom-center' => __('Nere i mitten'), 'bottom-right' => __('Nere till höger'),
    ];
    $placeable = collect($pageSizes)->filter(fn (array $size): bool => $size['rotation'] % 360 === 0)->keys();
    $signerName = fn (int $index): string => __('Undertecknare :number', ['number' => $index + 1]).(($signers[$index]['email'] ?? '') !== '' ? ' ('.$signers[$index]['email'].')' : '');
    $typeLabel = fn (string $value): string => App\Domain\Signing\Fields\FieldType::from($value)->label();
    $selectedField = $selected === null ? null : ($fields[$selected] ?? null);
@endphp

<x-ui.card :title="__('Fält i dokumentet')" :description="__('Frivilligt. Placera de fält undertecknarna ska fylla i, till exempel underskrift, datum och namn. Utan fält skriver undertecknarna under på sidan under dokumentet. Fälten går inte att ändra när dokumentet är skickat.')">
    @if($pageSizes === [])
        <p class="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-700" data-testid="signing-editor-unavailable">{{ $pagesNote ?? __('Sidorna mäts när steget öppnas.') }}</p>
    @else
        <div data-signing-editor data-pdf-url="{{ $pdfUrl }}" data-failed-text="{{ __('Förhandsvisningen av sidorna kunde inte laddas. Du kan fortfarande placera fält med formuläret till höger.') }}" class="mt-4 grid gap-6 xl:grid-cols-[minmax(0,1fr)_26rem]" data-testid="signing-editor">
            <div class="min-w-0">
                <p id="signing-editor-keys" class="rounded-lg bg-gray-50 p-3 text-sm text-gray-700">
                    {{ __('Dra ett fält till en ny plats och dra i det nedre högra hörnet för att ändra storleken. Utan mus: markera fältet med Tab och flytta det med piltangenterna (1 mm, med Skift 10 mm). Alt och en piltangent ändrar storleken, Delete tar bort fältet. Formuläret till höger gör samma sak med siffror.') }}
                </p>

                <div class="mt-4 space-y-6" data-testid="signing-editor-pages">
                    @foreach($pageSizes as $pageIndex => $size)
                        @php
                            $pageNumber = $pageIndex + 1;
                            $rotated = $size['rotation'] % 360 !== 0;
                        @endphp
                        <section aria-labelledby="signing-editor-page-{{ $pageNumber }}-title" class="mx-auto max-w-3xl">
                            <h3 id="signing-editor-page-{{ $pageNumber }}-title" class="mb-1 text-sm font-medium text-gray-900">{{ __('Sida :page av :pages', ['page' => $pageNumber, 'pages' => count($pageSizes)]) }}@if($rotated) · {{ __('roterad, inga fält kan placeras här') }}@endif</h3>
                            <div class="relative w-full bg-white shadow-sm ring-1 ring-stone-300" style="aspect-ratio: {{ $size['width'] }} / {{ $size['height'] }}" data-editor-page="{{ $pageNumber }}" data-page-width="{{ $size['width'] }}" data-page-height="{{ $size['height'] }}" @if($rotated) data-rotated @endif>
                                <div wire:ignore data-pdf-host class="absolute inset-0 overflow-hidden" aria-hidden="true"></div>
                                @foreach($fields as $fieldIndex => $field)
                                    @continue((int) $field['page'] !== $pageNumber)
                                    @php
                                        $isSelected = $selected === $fieldIndex;
                                        $left = $field['x'] / $size['width'] * 100;
                                        $top = ($size['height'] - $field['y'] - $field['height']) / $size['height'] * 100;
                                    @endphp
                                    <button type="button"
                                            wire:key="signing-field-box-{{ $fieldIndex }}"
                                            wire:click="selectField({{ $fieldIndex }})"
                                            data-field-box="{{ $fieldIndex }}"
                                            aria-describedby="signing-editor-keys"
                                            aria-label="{{ __(':label, :type, :signer, sida :page', ['label' => $field['label'], 'type' => $typeLabel($field['type']), 'signer' => $signerName((int) $field['signer']), 'page' => $pageNumber]) }}"
                                            @if($isSelected) aria-current="true" @endif
                                            style="left: {{ round($left, 3) }}%; top: {{ round($top, 3) }}%; width: {{ round($field['width'] / $size['width'] * 100, 3) }}%; height: {{ round($field['height'] / $size['height'] * 100, 3) }}%"
                                            @class(['absolute flex min-h-0 touch-none cursor-move items-start overflow-hidden rounded-xs border-2 px-1 text-left text-[10px] leading-tight focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink', $tones[(int) $field['signer'] % count($tones)], 'z-20 ring-2 ring-ink ring-offset-1' => $isSelected, 'z-10' => ! $isSelected])>
                                        <span class="truncate">{{ (int) $field['signer'] + 1 }} · {{ $field['label'] }}</span>
                                        @if($isSelected)
                                            <span data-resize-handle aria-hidden="true" class="absolute bottom-0 right-0 size-4 cursor-nwse-resize bg-ink"></span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            </div>

            <div class="space-y-6 xl:sticky xl:top-4 xl:self-start">
                <p class="sr-only" role="status" aria-live="polite" data-testid="signing-editor-status">{{ $announce }}</p>

                @if($errors->has('fields') || collect($errors->keys())->contains(fn (string $key): bool => str_starts_with($key, 'fields.')))
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900" role="alert" data-testid="signing-editor-errors">
                        <ul class="list-disc ps-5">
                            @foreach($errors->getBag('default')->messages() as $key => $messages)
                                @continue(! str_starts_with($key, 'fields'))
                                @foreach($messages as $message)
                                    <li>{{ preg_match('/^fields\.(\d+)$/', $key, $match) === 1 ? __('Fält :number: ', ['number' => ((int) $match[1]) + 1]) : '' }}{{ $message }}</li>
                                @endforeach
                            @endforeach
                        </ul>
                    </div>
                @endif

                <fieldset class="space-y-3 rounded-lg border border-gray-200 p-3" data-testid="signing-editor-add">
                    <legend class="px-1 text-sm font-semibold text-gray-900">{{ __('Lägg till fält') }}</legend>
                    <ul class="flex flex-wrap gap-2" aria-label="{{ __('Fälttyper att dra till sidan') }}">
                        @foreach($fieldTypes as $type)
                            <li>
                                <button type="button" draggable="true" data-palette-type="{{ $type->value }}" wire:click="$set('new.type', '{{ $type->value }}')" aria-pressed="{{ ($new['type'] ?? '') === $type->value ? 'true' : 'false' }}" @class(['cursor-grab rounded-lg px-3 text-sm ring-1 min-h-11 sm:min-h-0 sm:py-1.5', 'bg-ink text-white ring-ink' => ($new['type'] ?? '') === $type->value, 'bg-white text-ink ring-stone-300 hover:bg-gray-50' => ($new['type'] ?? '') !== $type->value])>{{ $type->label() }}</button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.field for="signing-new-type" :label="__('Typ')">
                            <select id="signing-new-type" class="{{ $input }} mt-1" wire:model="new.type">
                                @foreach($fieldTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="signing-new-signer" :label="__('Undertecknare')">
                            <select id="signing-new-signer" class="{{ $input }} mt-1" wire:model="new.signer">
                                @foreach($signers as $signerIndex => $signer)
                                    <option value="{{ $signerIndex }}">{{ $signerName($signerIndex) }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="signing-new-page" :label="__('Sida')">
                            <select id="signing-new-page" class="{{ $input }} mt-1" wire:model="new.page">
                                @foreach($placeable as $pageIndex)
                                    <option value="{{ $pageIndex + 1 }}">{{ $pageIndex + 1 }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="signing-new-preset" :label="__('Plats på sidan')">
                            <select id="signing-new-preset" class="{{ $input }} mt-1" wire:model="new.preset">
                                @foreach($presets as $preset)
                                    <option value="{{ $preset }}">{{ $presetLabels[$preset] }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-button type="button" wire:click="addField" :disabled="$placeable->isEmpty() || count($fields) >= $maxFields">{{ __('Lägg till fält') }}</x-button>
                        <span class="text-sm text-gray-600">{{ __(':count av :max fält', ['count' => count($fields), 'max' => $maxFields]) }}</span>
                    </div>
                    <p class="text-sm text-gray-600">{{ __('Med musen: välj undertecknare ovan och dra en fälttyp till sidan.') }}</p>
                </fieldset>

                <div data-testid="signing-editor-list">
                    <h3 class="text-sm font-semibold text-gray-900">{{ __('Fälten') }}</h3>
                    @if($fields === [])
                        <p class="mt-1 text-sm text-gray-600">{{ __('Inga fält än. Utan fält skriver undertecknarna under på sidan under dokumentet.') }}</p>
                    @else
                        <ol class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm">
                            @foreach($fields as $fieldIndex => $field)
                                <li wire:key="signing-field-row-{{ $fieldIndex }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                    <button type="button" class="min-h-11 text-start font-medium text-accent-700 hover:text-accent-800 sm:min-h-0" wire:click="selectField({{ $fieldIndex }})" @if($selected === $fieldIndex) aria-current="true" @endif>
                                        {{ $field['label'] }}
                                    </button>
                                    <span class="text-gray-600">{{ $typeLabel($field['type']) }} · {{ $signerName((int) $field['signer']) }} · {{ __('sida :page', ['page' => $field['page']]) }}</span>
                                    <button type="button" class="ms-auto min-h-11 text-gray-700 underline sm:min-h-0" wire:click="removeField({{ $fieldIndex }})" aria-label="{{ __('Ta bort fältet :label', ['label' => $field['label']]) }}">{{ __('Ta bort') }}</button>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>

                @if($selectedField !== null)
                    @php
                        $selectedType = App\Domain\Signing\Fields\FieldType::from($selectedField['type']);
                        $n = $selected;
                    @endphp
                    <fieldset class="space-y-3 rounded-lg border-2 border-ink/20 p-3" data-testid="signing-editor-edit">
                        <legend class="px-1 text-sm font-semibold text-gray-900">{{ __('Fält :number: :type', ['number' => $n + 1, 'type' => $selectedType->label()]) }}</legend>
                        <x-ui.field for="signing-field-label" :label="__('Fältets namn')" error="fields.{{ $n }}.label" :hint="__('Visas för undertecknaren och i dokumentets fältlista.')">
                            <input id="signing-field-label" type="text" class="{{ $input }} mt-1" wire:model.blur="fields.{{ $n }}.label" maxlength="100" autocomplete="off">
                        </x-ui.field>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.field for="signing-field-signer" :label="__('Undertecknare')">
                                <select id="signing-field-signer" class="{{ $input }} mt-1" wire:model.live="fields.{{ $n }}.signer">
                                    @foreach($signers as $signerIndex => $signer)
                                        <option value="{{ $signerIndex }}">{{ $signerName($signerIndex) }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                            <x-ui.field for="signing-field-page" :label="__('Sida')">
                                <select id="signing-field-page" class="{{ $input }} mt-1" wire:model.live="fields.{{ $n }}.page">
                                    @foreach($placeable as $pageIndex)
                                        <option value="{{ $pageIndex + 1 }}">{{ $pageIndex + 1 }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        </div>
                        @unless($selectedType->isAutomatic())
                            <label class="flex items-start gap-3 text-sm">
                                <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model.live="fields.{{ $n }}.required">
                                <span>{{ __('Obligatoriskt: undertecknaren måste fylla i fältet') }}</span>
                            </label>
                        @endunless

                        <fieldset class="grid grid-cols-2 gap-3" aria-describedby="signing-geometry-hint">
                            <legend class="text-sm font-medium text-gray-900">{{ __('Plats och storlek (millimeter)') }}</legend>
                            <x-ui.field for="signing-geometry-left" :label="__('Från vänster')" error="fields.{{ $n }}">
                                <input id="signing-geometry-left" type="number" step="0.1" min="0" class="{{ $input }} mt-1" wire:model="geometry.left" wire:change="applyGeometry" inputmode="decimal">
                            </x-ui.field>
                            <x-ui.field for="signing-geometry-top" :label="__('Från överkanten')">
                                <input id="signing-geometry-top" type="number" step="0.1" min="0" class="{{ $input }} mt-1" wire:model="geometry.top" wire:change="applyGeometry" inputmode="decimal">
                            </x-ui.field>
                            <x-ui.field for="signing-geometry-width" :label="__('Bredd')">
                                <input id="signing-geometry-width" type="number" step="0.1" min="1" class="{{ $input }} mt-1" wire:model="geometry.width" wire:change="applyGeometry" inputmode="decimal">
                            </x-ui.field>
                            <x-ui.field for="signing-geometry-height" :label="__('Höjd')">
                                <input id="signing-geometry-height" type="number" step="0.1" min="1" class="{{ $input }} mt-1" wire:model="geometry.height" wire:change="applyGeometry" inputmode="decimal">
                            </x-ui.field>
                            <p id="signing-geometry-hint" class="col-span-2 text-sm text-gray-600">{{ __('Måtten räknas från sidans övre vänstra hörn. Fältet hålls inom sidan och är aldrig mindre än typen kräver.') }}</p>
                        </fieldset>

                        <div role="group" aria-label="{{ __('Flytta fältet') }}" class="flex flex-wrap gap-2">
                            <button type="button" class="{{ $button }}" wire:click="nudge({{ $n }}, -1, 0)" aria-label="{{ __('Flytta 1 mm åt vänster') }}">←</button>
                            <button type="button" class="{{ $button }}" wire:click="nudge({{ $n }}, 1, 0)" aria-label="{{ __('Flytta 1 mm åt höger') }}">→</button>
                            <button type="button" class="{{ $button }}" wire:click="nudge({{ $n }}, 0, -1)" aria-label="{{ __('Flytta 1 mm uppåt') }}">↑</button>
                            <button type="button" class="{{ $button }}" wire:click="nudge({{ $n }}, 0, 1)" aria-label="{{ __('Flytta 1 mm nedåt') }}">↓</button>
                            <button type="button" class="{{ $button }}" wire:click="nudge({{ $n }}, -10, 0)" aria-label="{{ __('Flytta 10 mm åt vänster') }}">⇐</button>
                            <button type="button" class="{{ $button }}" wire:click="nudge({{ $n }}, 10, 0)" aria-label="{{ __('Flytta 10 mm åt höger') }}">⇒</button>
                            <button type="button" class="{{ $button }}" wire:click="nudge({{ $n }}, 0, -10)" aria-label="{{ __('Flytta 10 mm uppåt') }}">⇑</button>
                            <button type="button" class="{{ $button }}" wire:click="nudge({{ $n }}, 0, 10)" aria-label="{{ __('Flytta 10 mm nedåt') }}">⇓</button>
                        </div>

                        @if($selectedType === App\Domain\Signing\Fields\FieldType::Text)
                            <x-ui.field for="signing-field-max-length" :label="__('Längsta text (tecken)')" error="fields.{{ $n }}" :hint="__('Högst 500, och aldrig mer än det som får plats i rutan.')">
                                <input id="signing-field-max-length" type="number" min="1" max="500" class="{{ $input }} mt-1" wire:model.blur="fields.{{ $n }}.rules.max_length">
                            </x-ui.field>
                        @endif
                        @if($selectedType === App\Domain\Signing\Fields\FieldType::Number)
                            <div class="grid gap-3 sm:grid-cols-3">
                                <x-ui.field for="signing-field-min" :label="__('Minst')">
                                    <input id="signing-field-min" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model.blur="fields.{{ $n }}.rules.min" autocomplete="off">
                                </x-ui.field>
                                <x-ui.field for="signing-field-max" :label="__('Högst')">
                                    <input id="signing-field-max" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model.blur="fields.{{ $n }}.rules.max" autocomplete="off">
                                </x-ui.field>
                                <x-ui.field for="signing-field-decimals" :label="__('Decimaler')">
                                    <input id="signing-field-decimals" type="number" min="0" max="6" class="{{ $input }} mt-1" wire:model.blur="fields.{{ $n }}.rules.decimals">
                                </x-ui.field>
                            </div>
                        @endif
                        @if($selectedType === App\Domain\Signing\Fields\FieldType::Dropdown)
                            <x-ui.field for="signing-field-options" :label="__('Alternativ, ett per rad')" error="fields.{{ $n }}" :hint="__('1 till :max alternativ, högst :length tecken var.', ['max' => App\Domain\Signing\Fields\SigningFields::MAX_OPTIONS, 'length' => App\Domain\Signing\Fields\SigningFields::MAX_OPTION_LENGTH])">
                                <textarea id="signing-field-options" rows="4" class="{{ $input }} mt-1" wire:model.blur="fields.{{ $n }}.options_text"></textarea>
                            </x-ui.field>
                        @endif
                        @if($selectedType === App\Domain\Signing\Fields\FieldType::Radio)
                            <div class="grid gap-3 sm:grid-cols-2">
                                <x-ui.field for="signing-field-group" :label="__('Grupp')" error="fields.{{ $n }}" :hint="__('Alternativ med samma grupp är ett val: undertecknaren väljer ett av dem. Bokstäver, siffror, - och _.')">
                                    <input id="signing-field-group" type="text" class="{{ $input }} mt-1" wire:model.blur="fields.{{ $n }}.group_key" maxlength="40" autocomplete="off">
                                </x-ui.field>
                                <x-ui.field for="signing-field-value" :label="__('Alternativets värde')">
                                    <input id="signing-field-value" type="text" class="{{ $input }} mt-1" wire:model.blur="fields.{{ $n }}.options_text" maxlength="100" autocomplete="off">
                                </x-ui.field>
                            </div>
                        @endif
                    </fieldset>
                @endif
            </div>
        </div>
    @endif

    <div class="mt-6 flex flex-wrap items-center gap-4">
        <x-button type="button" wire:click="next">{{ __('Nästa') }}</x-button>
        <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="back">{{ __('Tillbaka') }}</button>
    </div>
</x-ui.card>
