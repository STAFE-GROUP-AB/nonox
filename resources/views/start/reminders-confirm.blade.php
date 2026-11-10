<x-start-layout title="Inga fler påminnelser">
    <div class="card">
        <h1>{{ __('Inga fler påminnelser') }}</h1>
        <p class="lead">{{ __('Klicka på knappen så slutar vi skicka påminnelser om att komma igång. Mejl om din inloggning och om dina uppgifter påverkas inte.') }}</p>
        <form method="POST" action="{{ $action }}">
            @csrf
            <div class="actions">
                <button class="button wide" type="submit">{{ __('Inga fler påminnelser') }}</button>
            </div>
        </form>
    </div>
</x-start-layout>
