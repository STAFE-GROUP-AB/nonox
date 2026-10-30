@props(['submit'])

<div {{ $attributes->merge(['class' => 'md:grid md:grid-cols-3 md:gap-6']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="mt-5 md:mt-0 md:col-span-2">
        <form wire:submit="{{ $submit }}">
            <div class="bg-white px-4 py-5 shadow-sm ring-1 ring-stone-200 sm:p-6 {{ isset($actions) ? 'rounded-t-3xl' : 'rounded-3xl' }}">
                <div class="grid grid-cols-6 gap-6">
                    {{ $form }}
                </div>
            </div>

            @if (isset($actions))
                <div class="flex items-center justify-end rounded-b-3xl bg-paper px-4 py-3 text-end ring-1 ring-stone-200 sm:px-6">
                    {{ $actions }}
                </div>
            @endif
        </form>
    </div>
</div>
