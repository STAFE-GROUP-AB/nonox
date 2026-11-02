{{--
    The article's pictures (ADR-039): the full editor on the Avancerad tab,
    a compact strip on Enkel ($compact). The first picture is the main image.
    Pictures of a new article wait for the save. NONOX AI's button sits here.
--}}
@php
    $total = $images->count() + count($pending);
    $thumb = fn ($image) => route('inventory.articleImages.show', ['article' => $image->article_id, 'image' => $image->id, 'storlek' => 'liten']);
@endphp
<section @class(['sm:col-span-6', 'rounded-2xl bg-stone-50 p-4' => ! $compact]) aria-labelledby="article-images-heading">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 id="article-images-heading" @class(['font-medium text-ink', 'text-sm' => $compact])>{{ __('Bilder') }}</h3>
            @unless($compact)
                <p class="text-sm text-gray-600">{{ __('Den första bilden är huvudbild. Dra bilderna för att ändra ordning, eller använd pilarna.') }}</p>
            @endunless
        </div>
        <div class="flex flex-wrap gap-2">
            <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg bg-ink px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus-within:ring-2 focus-within:ring-accent-500 sm:min-h-0">
                <span>{{ __('Ta foto') }}</span>
                <input type="file" accept="image/*" capture="environment" class="sr-only" wire:model="upload">
            </label>
            <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-300 hover:ring-stone-400 focus-within:ring-2 focus-within:ring-accent-500 sm:min-h-0">
                <span>{{ __('Välj bilder') }}</span>
                <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" multiple class="sr-only" wire:model="upload">
            </label>
        </div>
    </div>

    <p class="mt-2 text-sm text-gray-600" wire:loading wire:target="upload" aria-live="polite">{{ __('Laddar upp…') }}</p>
    @error('upload.*')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror

    @if($total === 0)
        <p class="mt-3 text-sm text-gray-600">{{ __('Inga bilder ännu. Ta ett foto med mobilen eller välj bilder i JPG, PNG, WebP eller HEIC.') }}</p>
    @elseif($compact)
        <ul class="mt-3 flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('Artikelns bilder') }}">
            @foreach($images as $image)
                <li wire:key="strip-image-{{ $image->id }}" class="shrink-0">
                    <img src="{{ $thumb($image) }}" alt="{{ $image->alt ?: __('Bild :n', ['n' => $loop->iteration]) }}" class="size-16 rounded-lg object-cover ring-1 ring-stone-200" loading="lazy">
                </li>
            @endforeach
            @foreach($pending as $index => $file)
                <li wire:key="strip-pending-{{ $index }}" class="shrink-0">
                    @if($file->isPreviewable())
                        <img src="{{ $file->temporaryUrl() }}" alt="{{ __('Ny bild :n', ['n' => $index + 1]) }}" class="size-16 rounded-lg object-cover ring-1 ring-stone-200">
                    @else
                        <span class="flex size-16 items-center justify-center rounded-lg bg-white text-xs text-gray-600 ring-1 ring-stone-200">HEIC</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <ul class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3" wire:sort="moveImage" aria-label="{{ __('Artikelns bilder') }}">
            @foreach($images as $image)
                <li wire:key="image-{{ $image->id }}" wire:sort:item="{{ $image->id }}" class="flex gap-3 rounded-xl bg-white p-3 ring-1 ring-stone-200">
                    <img src="{{ $thumb($image) }}" alt="{{ $image->alt ?: __('Bild :n', ['n' => $loop->iteration]) }}" class="size-24 shrink-0 cursor-grab rounded-lg object-cover" loading="lazy">
                    <div class="min-w-0 flex-1 space-y-2" wire:sort:ignore>
                        @if($loop->first)
                            <x-ui.badge>{{ __('Huvudbild') }}</x-ui.badge>
                        @endif
                        <label class="block text-xs font-medium text-gray-700" for="image-alt-{{ $image->id }}">{{ __('Bildtext') }}</label>
                        <input id="image-alt-{{ $image->id }}" type="text" maxlength="255" class="{{ $input }} text-sm" wire:model="alts.{{ $image->id }}" wire:change="saveAlt({{ $image->id }})" placeholder="{{ __('Vad visar bilden?') }}">
                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            @unless($loop->first)
                                <button type="button" class="font-medium text-accent-700 underline" wire:click="moveImage({{ $image->id }}, {{ $loop->index - 1 }})" aria-label="{{ __('Flytta bild :n bakåt', ['n' => $loop->iteration]) }}">{{ __('← Bakåt') }}</button>
                            @endunless
                            @unless($loop->last)
                                <button type="button" class="font-medium text-accent-700 underline" wire:click="moveImage({{ $image->id }}, {{ $loop->index + 1 }})" aria-label="{{ __('Flytta bild :n framåt', ['n' => $loop->iteration]) }}">{{ __('Framåt →') }}</button>
                            @endunless
                            <button type="button" class="text-gray-700 underline" wire:click="deleteImage({{ $image->id }})" wire:confirm="{{ __('Ta bort bilden? Den går inte att få tillbaka.') }}">{{ __('Ta bort') }}</button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        @if($pending !== [])
            <ul class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label="{{ __('Bilder som sparas med artikeln') }}">
                @foreach($pending as $index => $file)
                    <li wire:key="pending-{{ $index }}" class="flex items-center gap-3 rounded-xl bg-white p-3 ring-1 ring-stone-200">
                        @if($file->isPreviewable())
                            <img src="{{ $file->temporaryUrl() }}" alt="{{ __('Ny bild :n', ['n' => $index + 1]) }}" class="size-24 shrink-0 rounded-lg object-cover">
                        @else
                            <span class="flex size-24 shrink-0 items-center justify-center rounded-lg bg-stone-100 text-xs text-gray-600">HEIC</span>
                        @endif
                        <div class="space-y-2 text-sm">
                            @if($index === 0)<x-ui.badge>{{ __('Huvudbild') }}</x-ui.badge>@endif
                            <p class="text-gray-600">{{ __('Sparas när du sparar artikeln.') }}</p>
                            <button type="button" class="text-gray-700 underline" wire:click="removePending({{ $index }})">{{ __('Ta bort') }}</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    @if($aiAvailable)
        <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-stone-200 pt-3"
             x-data="{
                 running: false, left: 20, timer: null,
                 run() {
                     if (this.running) return;
                     this.running = true;
                     this.left = 20;
                     this.timer = setInterval(() => { if (this.left > 0) this.left--; }, 1000);
                     $wire.call('suggestWithAi').finally(() => { clearInterval(this.timer); this.running = false; });
                 },
             }"
             x-on:{{ App\Livewire\Invoicing\Articles::AI_EVENT }}.window="run()">
            <button type="button" x-on:click="run()" x-bind:disabled="running || {{ $total === 0 ? 'true' : 'false' }}"
                class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-accent-700 ring-1 ring-accent-300 hover:bg-accent-50 disabled:cursor-not-allowed disabled:opacity-60 sm:min-h-0">
                <span x-show="! running">{{ __('Fyll i med NONOX AI') }}</span>
                <span x-show="running" x-cloak class="tabular-nums" x-text="left > 0 ? left + ' s' : '…'"></span>
            </button>
            <p class="text-xs text-gray-600" x-show="! running">
                {{ $total === 0 ? __('Lägg till en bild först.') : __('Bilderna skickas till ert AI-konto. Du väljer själv vilka förslag som används.') }}
            </p>
            <p class="text-xs text-gray-700" x-show="running" x-cloak aria-live="polite">{{ __('NONOX AI tittar på bilderna, brukar ta högst 20 sekunder.') }}</p>
        </div>
    @endif
</section>
