{{-- The search box: resources/js/manual.js fetches the index ($searchUrl) the first time it is used. $id names it, $hero makes it the big one. --}}
@php($hero = $hero ?? false)
<div @class(['mn-search', 'mn-search-hero' => $hero]) role="search" data-mn-search data-index="{{ $searchUrl }}">
  <label for="{{ $id }}">Sök i manualen</label>
  <div class="mn-search-field">
    {!! App\Support\Manual\Icons::svg('search', 18) !!}
    <input id="{{ $id }}" type="search" autocomplete="off" spellcheck="false" enterkeyhint="search"
           placeholder="{{ $hero ? 'Sök, till exempel moms, lönebesked eller SIE' : 'Sök i manualen' }}"
           aria-describedby="{{ $id }}-hint" aria-controls="{{ $id }}-results" data-mn-input>
    <kbd class="mn-search-key" aria-hidden="true">/</kbd>
  </div>
  <p class="mn-search-hint" id="{{ $id }}-hint">Tryck <kbd>/</kbd> för att söka. Pil ned går till träffarna.</p>
  <div id="{{ $id }}-results" data-mn-results data-empty="Inget hittades. Försök med ett annat ord, till exempel det du ser på knappen i appen." data-error="Sökningen kunde inte laddas. Försök igen om en stund."></div>
  <p class="mn-sr" role="status" data-mn-status></p>
</div>
