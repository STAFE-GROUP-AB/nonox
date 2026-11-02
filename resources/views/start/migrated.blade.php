<x-start-layout title="Databasen">
    <div class="card">
        @if($ok)
            <div class="step">Klart</div>
            <h1>Databasen är uppdaterad</h1>
            <p class="lead">Alla tabeller är på plats. Du kan fortsätta.</p>
        @else
            <div class="step">Nästan klart</div>
            <h1>Databasen kunde inte uppdateras helt</h1>
            <p class="lead">Läs utskriften nedan. Du kan också köra raden i Terminalen, i mappen där systemet ligger.</p>
            <pre class="command">php artisan migrate</pre>
        @endif

        <div class="actions">
            <a class="button" href="{{ url('/') }}">Fortsätt</a>
        </div>

        @if($output !== '')
            <details @if(! $ok) open @endif>
                <summary>Visa vad som kördes</summary>
                <pre class="small" style="margin-top: .75rem; white-space: pre-wrap; overflow-x: auto;">{{ $output }}</pre>
            </details>
        @endif
    </div>
</x-start-layout>
