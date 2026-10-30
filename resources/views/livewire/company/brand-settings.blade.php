@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm')
<div class="space-y-6">
    <x-ui.card :title="__('Varumärke')" :description="__('Er logga och era färger på fakturor, offerter, order, lönebesked och Min sida för anställda.')">
        <form wire:submit="save" class="space-y-6">
            <div>
                <p class="text-sm font-medium text-gray-700">{{ __('Logga') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-4">
                    <div class="flex h-20 w-48 items-center justify-center rounded-lg border border-dashed border-gray-300 bg-white p-2">
                        @if($logoUrl)
                            <img src="{{ $logoUrl }}" alt="{{ __('Logga för :company', ['company' => $company->name]) }}" class="max-h-16 max-w-full object-contain">
                        @else
                            <span class="text-xs text-gray-500">{{ __('Ingen logga') }}</span>
                        @endif
                    </div>
                    <div class="space-y-2 text-sm">
                        <label class="inline-flex cursor-pointer items-center rounded-lg border border-gray-300 bg-white px-3 py-2 font-medium text-gray-700 hover:border-gray-400">
                            <input type="file" class="sr-only" accept="image/png,image/jpeg,image/webp" wire:model="logo">
                            {{ $company->logo_path ? __('Byt logga') : __('Ladda upp logga') }}
                        </label>
                        <p class="text-xs text-gray-500" wire:loading wire:target="logo">{{ __('Laddar upp…') }}</p>
                        @if($company->logo_path && ! $logo)
                            <button type="button" class="block text-xs font-medium text-gray-600 underline" wire:click="removeLogo">{{ __('Ta bort loggan') }}</button>
                        @endif
                        <p class="text-xs text-gray-500">{{ __('PNG, JPEG eller WebP, högst 2 MB. En liggande logga med genomskinlig bakgrund blir finast.') }}</p>
                    </div>
                </div>
                <x-input-error for="logo" class="mt-2" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="brand_color" :label="__('Huvudfärg')" :hint="__('Fält, summor och markeringar.')">
                    <div class="mt-1 flex items-center gap-2">
                        <input type="color" class="h-10 w-12 cursor-pointer rounded border border-gray-300 bg-white p-1" value="{{ $preview->color }}" wire:model.live="color" aria-label="{{ __('Välj huvudfärg') }}">
                        <input id="brand_color" type="text" class="{{ $input }} font-mono uppercase" placeholder="{{ App\Support\Branding\Brand::DEFAULT_COLOR }}" wire:model.live.debounce.400ms="color" maxlength="7">
                    </div>
                    <x-input-error for="brand_color" class="mt-1" />
                </x-ui.field>
                <x-ui.field for="brand_accent" :label="__('Accentfärg')" :hint="__('Rubriker och etiketter. Tom betyder samma som huvudfärgen.')">
                    <div class="mt-1 flex items-center gap-2">
                        <input type="color" class="h-10 w-12 cursor-pointer rounded border border-gray-300 bg-white p-1" value="{{ $preview->accent }}" wire:model.live="accent" aria-label="{{ __('Välj accentfärg') }}">
                        <input id="brand_accent" type="text" class="{{ $input }} font-mono uppercase" placeholder="{{ $preview->color }}" wire:model.live.debounce.400ms="accent" maxlength="7">
                    </div>
                    <x-input-error for="brand_accent_color" class="mt-1" />
                </x-ui.field>
            </div>

            @if($contrast < 3)
                <p class="rounded-lg bg-accent-50 px-3 py-2 text-sm text-accent-800">{{ __('Huvudfärgen är ljus. Text på den skrivs svart, men en mörkare färg syns tydligare på papper.') }}</p>
            @endif

            <div>
                <p class="text-sm font-medium text-gray-700">{{ __('Så här ser en faktura ut') }}</p>
                <div class="mt-2 overflow-hidden rounded-lg border border-gray-200 bg-white text-xs shadow-sm" aria-hidden="true">
                    <div style="height: 6px; background: {{ $preview->color }}"></div>
                    <div class="flex items-start justify-between gap-4 p-4">
                        <div>
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="" class="max-h-10 max-w-40 object-contain">
                            @else
                                <p class="text-sm font-bold text-gray-900">{{ $company->legal_name ?: $company->name }}</p>
                            @endif
                        </div>
                        <div class="text-right">
                            <p class="font-bold uppercase tracking-widest" style="color: {{ $preview->accent }}">{{ __('Faktura') }}</p>
                            <p class="text-lg font-bold text-gray-900">1042</p>
                        </div>
                    </div>
                    <div class="mx-4 border-t border-gray-200 py-2 text-gray-600">{{ __('Lunchbuffé, 12 personer') }} <span class="float-right tabular-nums">2 400,00</span></div>
                    <div class="flex justify-end p-4 pt-0">
                        <span class="rounded-md px-3 py-1.5 text-sm font-bold" style="background: {{ $preview->color }}; color: {{ $preview->onColor() }}">{{ __('Att betala') }} 3 000,00 kr</span>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <x-button wire:loading.attr="disabled">{{ __('Spara varumärket') }}</x-button>
                @if($color !== '' || $accent !== '')
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="resetColors">{{ __('Neutrala färger') }}</button>
                @endif
            </div>
        </form>
    </x-ui.card>
</div>
