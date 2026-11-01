{{-- Hjälp: the manual's article about the page ($helpUrl, from navigation-menu), in a new tab. Left out when the manual is off. --}}
@if($helpUrl)
    <a href="{{ $helpUrl }}" target="_blank" rel="noopener" data-help-link
       class="inline-flex items-center gap-1.5 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 {{ $helpClass }}"
       @unless($helpLabel) aria-label="{{ __('Hjälp om den här sidan (öppnas i en ny flik)') }}" @endunless>
        <svg class="{{ $helpLabel ? 'size-5' : 'size-6' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" /></svg>
        @if($helpLabel)
            <span>{{ __('Hjälp') }}<span class="sr-only"> {{ __('om den här sidan (öppnas i en ny flik)') }}</span></span>
        @endif
    </a>
@endif
