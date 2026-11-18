{{--
    Wraps controls that need the PDF engine (ADR-046, amendment 2026-11-18, owner
    decision 4): fields in the PDF and the seal. With the engine the slot is shown;
    without it, a plain notice "Kräver PDF-motorn" with a link to the self-hosting guide.
--}}
@props(['feature' => null])

@if(app(\App\Domain\Signing\Pdf\PdfEngineStatus::class)->usable())
    {{ $slot }}
@else
    <div {{ $attributes->merge(['class' => 'rounded-2xl bg-paper px-4 py-3 text-sm text-gray-700 ring-1 ring-stone-200']) }} role="note" data-requires-pdf-engine>
        <p class="font-semibold text-ink">{{ __('Kräver PDF-motorn') }}</p>
        <p class="mt-1">
            @if($feature)
                {{ __(':feature kräver PDF-motorn, som inte är installerad på den här installationen.', ['feature' => $feature]) }}
            @else
                {{ __('Det här kräver PDF-motorn, som inte är installerad på den här installationen.') }}
            @endif
            {{ __('Signering i övrigt fungerar som vanligt.') }}
        </p>
        <p class="mt-2"><x-ui.link href="{{ \App\Domain\Signing\Pdf\PdfEngineStatus::guideUrl() }}">{{ __('Så installeras PDF-motorn') }}</x-ui.link></p>
    </div>
@endif
