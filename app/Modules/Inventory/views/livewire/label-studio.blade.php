@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $isSheet = ($form['media'] ?? '') === 'SHEET';
    $isCustomSheet = $isSheet && ($form['sheet_preset'] ?? '') === 'custom';
@endphp
<div class="space-y-6">
    @if($editing !== null)
        <x-ui.card :title="$editing ? __('Ändra etikettmall') : __('Ny etikettmall: :kind', ['kind' => mb_strtolower($kind->label())])">
            <div class="grid gap-8 lg:grid-cols-2">
                <form wire:submit="save" class="grid content-start gap-5 sm:grid-cols-6">
                    <x-ui.field for="label-name" :label="__('Namn')" error="form.name" class="sm:col-span-6">
                        <input id="label-name" wire:model.blur="form.name" maxlength="100" class="{{ $input }}" required>
                    </x-ui.field>

                    <fieldset class="sm:col-span-6">
                        <legend class="text-sm font-medium text-gray-700">{{ __('Skrivs ut på') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-4">
                            @foreach(App\Modules\Inventory\Labels\LabelMedia::cases() as $media)
                                <label class="inline-flex min-h-11 items-center gap-2 text-sm text-gray-700">
                                    <input type="radio" wire:model.live="form.media" value="{{ $media->value }}" class="border-gray-300 text-accent-600 focus:ring-accent-500">
                                    {{ $media->label() }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    @if($isSheet)
                        <x-ui.field for="label-sheet" :label="__('Ark')" error="form.sheet_preset" class="sm:col-span-6" :hint="__('Mät ditt etikettark om du är osäker: etikettens storlek, avståndet från papperskanten till första etiketten och avståndet mellan etiketterna.')">
                            <select id="label-sheet" wire:model.live="form.sheet_preset" class="{{ $input }}">
                                @foreach($sheets as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif

                    @if($fixedSheet)
                        <p class="text-sm text-gray-600 sm:col-span-6">{{ __('Etikettens storlek bestäms av arket.') }}</p>
                    @else
                        <x-ui.field for="label-size" :label="__('Storlek')" class="sm:col-span-2">
                            <select id="label-size" wire:model.live="sizePreset" class="{{ $input }}">
                                @foreach($sizes as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="label-width" :label="__('Bredd, mm')" error="form.width_mm" class="sm:col-span-2">
                            <input id="label-width" wire:model.live.debounce.500ms="form.width_mm" inputmode="decimal" class="{{ $input }} text-right tabular-nums" @disabled($sizePreset !== 'custom')>
                        </x-ui.field>
                        <x-ui.field for="label-height" :label="__('Höjd, mm')" error="form.height_mm" class="sm:col-span-2">
                            <input id="label-height" wire:model.live.debounce.500ms="form.height_mm" inputmode="decimal" class="{{ $input }} text-right tabular-nums" @disabled($sizePreset !== 'custom')>
                        </x-ui.field>
                    @endif

                    @if($isCustomSheet)
                        <x-ui.field for="label-columns" :label="__('Kolumner')" error="form.columns" class="sm:col-span-2">
                            <input id="label-columns" type="number" min="1" max="20" wire:model.live.debounce.500ms="form.columns" class="{{ $input }} text-right tabular-nums">
                        </x-ui.field>
                        <x-ui.field for="label-rows" :label="__('Rader')" error="form.rows" class="sm:col-span-2">
                            <input id="label-rows" type="number" min="1" max="40" wire:model.live.debounce.500ms="form.rows" class="{{ $input }} text-right tabular-nums">
                        </x-ui.field>
                        <div class="hidden sm:col-span-2 sm:block"></div>
                        <x-ui.field for="label-top" :label="__('Övre marginal, mm')" error="form.sheet_top_mm" class="sm:col-span-3" :hint="__('Från papperets överkant till första etiketten.')">
                            <input id="label-top" wire:model.live.debounce.500ms="form.sheet_top_mm" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                        </x-ui.field>
                        <x-ui.field for="label-left" :label="__('Vänster marginal, mm')" error="form.sheet_left_mm" class="sm:col-span-3" :hint="__('Från papperets vänsterkant till första etiketten.')">
                            <input id="label-left" wire:model.live.debounce.500ms="form.sheet_left_mm" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                        </x-ui.field>
                        <x-ui.field for="label-pitch-x" :label="__('Avstånd i sidled, mm')" error="form.pitch_x_mm" class="sm:col-span-3" :hint="__('Från en etiketts vänsterkant till nästas.')">
                            <input id="label-pitch-x" wire:model.live.debounce.500ms="form.pitch_x_mm" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                        </x-ui.field>
                        <x-ui.field for="label-pitch-y" :label="__('Avstånd i höjdled, mm')" error="form.pitch_y_mm" class="sm:col-span-3" :hint="__('Från en etiketts överkant till nästas.')">
                            <input id="label-pitch-y" wire:model.live.debounce.500ms="form.pitch_y_mm" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                        </x-ui.field>
                    @endif

                    <x-ui.field for="label-padding" :label="__('Marginal inne på etiketten, mm')" error="form.padding_mm" class="sm:col-span-3">
                        <input id="label-padding" wire:model.live.debounce.500ms="form.padding_mm" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                    </x-ui.field>
                    <x-ui.field for="label-layout" :label="__('Layout')" error="form.layout" class="sm:col-span-3">
                        <select id="label-layout" wire:model.live="form.layout" class="{{ $input }}">
                            @foreach($layouts as $layout)
                                <option value="{{ $layout->value }}">{{ $layout->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    <fieldset class="sm:col-span-6">
                        <legend class="text-sm font-medium text-gray-700">{{ __('Visa på etiketten') }}</legend>
                        <div class="mt-2 grid gap-x-4 sm:grid-cols-2">
                            @foreach($elements as $element)
                                <label class="inline-flex min-h-11 items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" wire:model.live="form.elements" value="{{ $element->value }}" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                                    {{ $element->label($kind) }}
                                </label>
                            @endforeach
                        </div>
                        <x-input-error for="form.elements" class="mt-2" />
                    </fieldset>

                    @if(in_array('properties', $form['elements'] ?? [], true))
                        <fieldset class="sm:col-span-6">
                            <legend class="text-sm font-medium text-gray-700">{{ __('Vilka egenskaper') }}</legend>
                            @if($propertyKeys === [])
                                <p class="mt-1 text-sm text-gray-600">{{ __('Inga artiklar har egenskaper ännu. Lägg till dem under Avancerat i artikelregistret.') }}</p>
                            @else
                                <div class="mt-2 flex flex-wrap gap-x-4">
                                    @foreach($propertyKeys as $key)
                                        <label class="inline-flex min-h-11 items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" wire:model.live="form.property_keys" value="{{ $key }}" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                                            {{ $key }}
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        </fieldset>
                    @endif

                    @if(in_array('free_text', $form['elements'] ?? [], true))
                        <x-ui.field for="label-free-text" :label="__('Egen text')" error="form.free_text" class="sm:col-span-6" :hint="__('Samma text på varje etikett, till exempel öppettider eller Tål diskmaskin.')">
                            <input id="label-free-text" wire:model.live.debounce.500ms="form.free_text" maxlength="120" class="{{ $input }}">
                        </x-ui.field>
                    @endif

                    <div class="flex flex-wrap gap-3 sm:col-span-6">
                        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                        <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                        @if($editing)
                            <a href="{{ route('inventory.labels.sample', $editing) }}" target="_blank" class="inline-flex min-h-11 items-center text-sm font-semibold text-accent-700 underline">{{ __('Provsida som PDF (sparad mall)') }}</a>
                        @endif
                    </div>
                </form>

                <div class="space-y-4" aria-live="polite">
                    <h3 class="text-sm font-semibold text-ink">{{ __('Förhandsvisning') }}</h3>
                    @if($preview['errors'] !== [])
                        <div class="rounded-2xl bg-red-50 p-4 text-sm text-red-800" role="alert">
                            <p class="font-semibold">{{ __('Mallen går inte att spara än') }}</p>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @foreach($preview['errors'] as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        @php($t = $preview['template'])
                        <div class="overflow-x-auto rounded-2xl bg-stone-100 p-6">
                            <div class="mx-auto" style="width: calc({{ $t->width_mm }}mm * {{ $preview['scale'] }}); height: calc({{ $t->height_mm }}mm * {{ $preview['scale'] }});">
                                <div class="label-preview relative overflow-hidden bg-white text-black shadow-sm ring-1 ring-stone-300" style="width: {{ $t->width_mm }}mm; height: {{ $t->height_mm }}mm; transform: scale({{ $preview['scale'] }}); transform-origin: top left; font-family: 'DejaVu Sans', Verdana, sans-serif;">
                                    @include('inventory::labels.boxes', ['boxes' => $preview['boxes']])
                                </div>
                            </div>
                        </div>
                        <p class="text-sm text-gray-600">
                            {{ __('Skala :scale:1 · :size · med :sample', ['scale' => str_replace('.', ',', (string) round($preview['scale'], 1)), 'size' => App\Modules\Inventory\Labels\LabelSheets::mm($t->width_mm).' × '.App\Modules\Inventory\Labels\LabelSheets::mm($t->height_mm).' mm', 'sample' => $preview['sample']]) }}
                        </p>

                        @if($preview['warnings'] === [])
                            <p class="rounded-2xl bg-green-50 p-4 text-sm text-green-800">{{ __('Allt får plats och koderna går att läsa.') }}</p>
                        @else
                            <div class="rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
                                <p class="font-semibold">{{ __('Att tänka på') }}</p>
                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                    @foreach($preview['warnings'] as $warning)
                                        <li>{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if($t->media->value === 'SHEET')
                            <div>
                                <h4 class="text-sm font-semibold text-ink">{{ __('Så hamnar etiketterna på A4') }}</h4>
                                <svg viewBox="0 0 210 297" class="mt-2 h-64 w-auto rounded-sm bg-white ring-1 ring-stone-300" role="img" aria-label="{{ __(':count etiketter per ark, :columns kolumner och :rows rader', ['count' => $t->perPage(), 'columns' => $t->columns, 'rows' => $t->rows]) }}">
                                    @for($slot = 0; $slot < $t->perPage(); $slot++)
                                        @php([$x, $y] = $t->origin($slot))
                                        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $t->width_mm }}" height="{{ $t->height_mm }}" rx="1" fill="#e7e5e4" stroke="#78716c" stroke-width="0.4" />
                                    @endfor
                                </svg>
                                <p class="mt-1 text-sm text-gray-600">{{ __(':count etiketter per ark.', ['count' => $t->perPage()]) }}</p>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </x-ui.card>
    @endif

    @foreach($groups as $groupKind => $templates)
        @php($case = App\Modules\Inventory\Labels\LabelKind::from($groupKind))
        <x-ui.card :title="$case === App\Modules\Inventory\Labels\LabelKind::Article ? __('Artikeletiketter') : __('Platsetiketter')" :description="$case === App\Modules\Inventory\Labels\LabelKind::Article ? __('Pris, namn och streckkod för varorna. Standardmallen föreslås när du skriver ut.') : __('Koden på hyllor, fack och pallplatser, som skannern läser.')">
            <x-slot:cta>
                <x-button type="button" wire:click="create('{{ $groupKind }}')">{{ $case === App\Modules\Inventory\Labels\LabelKind::Article ? __('Ny artikelmall') : __('Ny platsmall') }}</x-button>
            </x-slot:cta>
            <ul class="divide-y divide-stone-200">
                @foreach($templates as $template)
                    <li wire:key="label-template-{{ $template->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <span>
                            <span class="font-semibold text-ink">{{ $template->name }}</span>
                            @if($template->is_default)<x-ui.badge tone="accent">{{ __('Standard') }}</x-ui.badge>@endif
                            <span class="block text-sm text-gray-600">{{ $template->summary() }} · {{ $template->layout->label() }}</span>
                        </span>
                        <span class="flex flex-wrap gap-3 text-sm font-semibold">
                            <button type="button" wire:click="edit({{ $template->id }})" class="text-accent-700 underline">{{ __('Ändra') }}</button>
                            <button type="button" wire:click="duplicate({{ $template->id }})" class="text-accent-700 underline">{{ __('Kopiera') }}</button>
                            @if(! $template->is_default)
                                <button type="button" wire:click="makeDefault({{ $template->id }})" class="text-accent-700 underline">{{ __('Gör till standard') }}</button>
                            @endif
                            @if($templates->count() > 1)
                                <button type="button" wire:click="delete({{ $template->id }})" wire:confirm="{{ $template->is_default ? __('Ta bort :name? Den är standardmall, så nästa mall blir standard i stället. Det går inte att ångra.', ['name' => $template->name]) : __('Ta bort :name? Det går inte att ångra.', ['name' => $template->name]) }}" class="text-gray-700 underline">{{ __('Ta bort') }}</button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endforeach
</div>
