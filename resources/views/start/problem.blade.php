<x-start-layout title="Nästan klart">
    <div class="card">
        @if($problem)
            <div class="step">Nästan klart</div>
            <h1>{{ $problem['title'] }}</h1>
            <p class="lead">{{ $problem['explanation'] }}</p>

            @if(($problem['action'] ?? null) === 'migrate' && App\Http\Controllers\RunMigrationsController::allowed(request()))
                <p class="lead">Du kör NONOX på din egen dator, så det går att göra här direkt. Inget tas bort: bara det som saknas läggs till.</p>
                <form method="POST" action="{{ URL::temporarySignedRoute('setup.migrate', now()->addMinutes(15)) }}" class="actions">
                    <button type="submit" class="button">Uppdatera databasen nu</button>
                </form>
                <p class="small">Eller kör raden i Terminalen, i mappen där systemet ligger:</p>
                <pre class="command">{{ $problem['command'] }}</pre>
            @else
                <pre class="command">{{ $problem['command'] }}</pre>

                <div class="actions">
                    <a class="button" href="{{ url('/') }}">Jag har gjort det, ladda om</a>
                </div>
            @endif

            <details>
                <summary>Visa tekniska detaljer</summary>
                <p class="small" style="margin-top: .75rem;">{{ $problem['details'] }}</p>
                <p class="small">Mer hjälp finns i filen README.md i mappen där systemet ligger.</p>
            </details>
        @else
            <h1>Systemet är inte igång just nu</h1>
            <p class="lead">Försök igen om en stund. Fortsätter det, kontakta den som sköter installationen.</p>
        @endif
    </div>
</x-start-layout>
