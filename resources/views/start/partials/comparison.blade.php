{{--
    Feature and price comparison with other programs (ADR-020), from
    docs/migration/jamforelse.json. $highlight is the slug of a program to
    bring forward next to NONOX (on an "alternativ till" page), or null.
--}}
@php
    $comparison = App\Support\Landing\Comparison::data();
    $programs = $comparison['programs'];
    if (($highlight ?? null) !== null) {
        usort($programs, fn ($a, $b) => [$a['slug'] !== 'nonox', $a['slug'] !== $highlight] <=> [$b['slug'] !== 'nonox', $b['slug'] !== $highlight]);
    }
    $kr = fn (int $kronor): string => number_format($kronor, 0, ',', "\u{a0}")."\u{a0}kr";
    $icon = [
        'yes' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="var(--accl)"></circle><path d="M7 12.5l3.2 3.2L17 9" fill="none" stroke="var(--acct)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
        'per' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="var(--soft)"></circle><path d="M7 12.5l3.2 3.2L17 9" fill="none" stroke="var(--muted)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
        'web' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="var(--accl)"></circle><path d="M7 12.5l3.2 3.2L17 9" fill="none" stroke="var(--acct)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
        'no' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 8l8 8M16 8l-8 8" fill="none" stroke="var(--muted)" stroke-width="2.2" stroke-linecap="round"></path></svg>',
    ];
@endphp
<div class="cmp-wrap">
  <table class="cmp-table">
    <caption class="cmp-sr">Jämförelse av funktioner och pris</caption>
    <thead>
      <tr>
        <th scope="col" class="cmp-feature-col">Funktion</th>
        @foreach($programs as $program)
          <th scope="col" @class(['cmp-nonox' => $program['slug'] === 'nonox'])>{{ $program['name'] }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @foreach($comparison['features'] as $feature)
        <tr>
          <th scope="row" class="cmp-feature-col">{{ $feature['name'] }}</th>
          @foreach($programs as $program)
            @php($value = $feature['values'][$program['slug']] ?? 'unknown')
            <td @class(['cmp-nonox' => $program['slug'] === 'nonox'])>
              @if(isset($icon[$value]))
                <span class="cmp-cell" title="{{ $comparison['legend'][$value] }}">{!! $icon[$value] !!}<span class="cmp-sr">{{ $comparison['legend'][$value] }}</span>@if($value === 'per')<span class="cmp-note">avgift</span>@elseif($value === 'web')<span class="cmp-note">webbapp</span>@endif</span>
              @elseif($value === 'addon')
                <span class="cmp-pill">Tillval</span>
              @else
                <span class="cmp-unknown" title="{{ $comparison['legend']['unknown'] }}">Anges ej</span>
              @endif
            </td>
          @endforeach
        </tr>
      @endforeach
      <tr class="cmp-price-row">
        <th scope="row" class="cmp-feature-col">Pris per år, fem anställda</th>
        @foreach($programs as $program)
          <td @class(['cmp-nonox' => $program['slug'] === 'nonox'])>
            <span class="cmp-price">{{ $kr($program['per_year']) }}</span>
            @unless($program['verified'])<span class="cmp-note">ej verifierat</span>@endunless
          </td>
        @endforeach
      </tr>
    </tbody>
  </table>
</div>
<div class="cmp-legend">
  <span>{!! $icon['yes'] !!} Ingår</span>
  <span>{!! $icon['per'] !!} Ingår, med avgift per anställd eller lönebesked</span>
  <span><span class="cmp-pill">Tillval</span> Kostar extra</span>
  <span>{!! $icon['web'] !!}<span class="cmp-note">webbapp</span> Installeras från webbläsaren (PWA), inte från App Store eller Google Play</span>
  <span>{!! $icon['no'] !!} Finns inte</span>
  <span><span class="cmp-unknown">Anges ej</span> Står inte på prissidan</span>
</div>
<details class="cmp-sources">
  <summary>Så har vi räknat, och källor</summary>
  <p>{{ $comparison['scenario'] }} Priserna hämtades från leverantörernas webbplatser {{ \Illuminate\Support\Str::of($comparison['fetched_at'])->replace(' ', ' kl. ') }} (svensk tid). Priser ändras; kontrollera alltid hos leverantören.</p>
  <ul>
    @foreach($programs as $program)
      <li><strong>{{ $program['name'] }}:</strong> {{ $program['plan'] }}. {{ $program['price_note'] }}@if($program['source']) <a href="{{ $program['source'] }}" rel="nofollow noopener" target="_blank">Källa</a>@endif</li>
    @endforeach
  </ul>
  <p>Funktionerna gäller det jämförda paketet enligt prissidan. Står en funktion inte på sidan skriver vi "Anges ej", även om den kan finnas i ett annat paket.</p>
</details>
