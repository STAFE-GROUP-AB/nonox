@props(['title', 'lead' => null])

<div {{ $attributes }}>
    <h1 class="text-3xl font-semibold tracking-tight text-gray-900">{{ $title }}</h1>
    @if($lead)
        <p class="mt-2 text-lg text-gray-600">{{ $lead }}</p>
    @endif
</div>
