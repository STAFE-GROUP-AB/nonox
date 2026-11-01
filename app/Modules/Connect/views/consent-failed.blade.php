<x-start-layout :title="__('Anslut app')">
    <div class="card">
        <h1>{{ __('Appen kunde inte anslutas') }}</h1>
        <p class="lead">{{ $message }}</p>
        <div class="actions">
            <a class="link" href="{{ route('dashboard') }}">{{ __('Till NONOX') }}</a>
        </div>
    </div>
</x-start-layout>
