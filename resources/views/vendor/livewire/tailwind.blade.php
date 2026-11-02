{{--
    Livewire's page links (livewire::tailwind) in NONOX colours and Swedish,
    accessible: a named navigation landmark, buttons of at least 40 px, a
    visible focus ring, the current page marked with aria-current, and no
    aria-label on elements without a role (docs/tillganglighet.md).
--}}
@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';

$base = 'relative inline-flex min-h-10 min-w-10 items-center justify-center border border-stone-300 bg-white px-3 text-sm font-medium leading-5';
$active = $base.' text-gray-700 hover:bg-stone-50 focus:z-10 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500';
$disabled = $base.' cursor-default text-gray-500';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav aria-label="{{ __('Sidor') }}" class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-700">
                {{ __('Visar :first–:last av :total', ['first' => $paginator->firstItem(), 'last' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
            </p>

            <ul class="inline-flex flex-wrap -space-x-px" role="list">
                <li>
                    @if ($paginator->onFirstPage())
                        <span class="{{ $disabled }} rounded-l-lg" aria-disabled="true"><span class="sr-only">{{ __('Föregående sida') }}</span><span aria-hidden="true">‹</span></span>
                    @else
                        <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="{{ $active }} rounded-l-lg"><span class="sr-only">{{ __('Föregående sida') }}</span><span aria-hidden="true">‹</span></button>
                    @endif
                </li>

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <li><span class="{{ $disabled }}" aria-hidden="true">{{ $element }}</span></li>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            <li wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}">
                                @if ($page == $paginator->currentPage())
                                    <span class="{{ $base }} bg-accent-50 font-semibold text-accent-700" aria-current="page"><span class="sr-only">{{ __('Sida') }}</span> {{ $page }}</span>
                                @else
                                    <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="{{ $active }}"><span class="sr-only">{{ __('Sida') }}</span> {{ $page }}</button>
                                @endif
                            </li>
                        @endforeach
                    @endif
                @endforeach

                <li>
                    @if ($paginator->hasMorePages())
                        <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="{{ $active }} rounded-r-lg"><span class="sr-only">{{ __('Nästa sida') }}</span><span aria-hidden="true">›</span></button>
                    @else
                        <span class="{{ $disabled }} rounded-r-lg" aria-disabled="true"><span class="sr-only">{{ __('Nästa sida') }}</span><span aria-hidden="true">›</span></span>
                    @endif
                </li>
            </ul>
        </nav>
    @endif
</div>
