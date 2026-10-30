@props(['title', 'lead' => null])

<div {{ $attributes }}>
    <h1 class="text-3xl font-bold tracking-tight text-ink sm:text-4xl">{{ $title }}</h1>
    @if($lead)
        <p class="mt-2 text-base text-gray-600 sm:text-lg">{{ $lead }}</p>
    @endif
</div>
