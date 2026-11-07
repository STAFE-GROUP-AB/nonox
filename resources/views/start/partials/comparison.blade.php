{{--
    Feature and price comparison with other programs (ADR-020), from
    docs/migration/jamforelse.json. $highlight is the slug of the one program
    an "alternativ till" page is about: then only NONOX and that program are
    compared. Null shows every program (the start page).
--}}
@php
    $comparison = App\Support\Landing\Comparison::data();
    $programs = $comparison['programs'];
    if (($highlight ?? null) !== null) {
        $programs = array_values(array_filter($programs, fn (array $program): bool => in_array($program['slug'], ['nonox', $highlight], true)));
    }
    $kr = fn (int $kronor): string => number_format($kronor, 0, ',', "\u{a0}")."\u{a0}kr";
    $icon = [
        'yes' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="var(--accl)"></circle><path d="M7 12.5l3.2 3.2L17 9" fill="none" stroke="var(--acct)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
        'per' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="var(--soft)"></circle><path d="M7 12.5l3.2 3.2L17 9" fill="none" stroke="var(--muted)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
        'web' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="var(--accl)"></circle><path d="M7 12.5l3.2 3.2L17 9" fill="none" stroke="var(--acct)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
        'self_hosted' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="var(--soft)"></circle><path d="M7 12.5l3.2 3.2L17 9" fill="none" stroke="var(--muted)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
        'own_key' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="var(--soft)"></circle><path d="M7 12.5l3.2 3.2L17 9" fill="none" stroke="var(--muted)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
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
          <th scope="row" class="cmp-feature-col">{{ $feature['name'] }}@if($feature['name'] === 'Alla moduler ingår i priset')<sup>*</sup>@endif</th>
          @foreach($programs as $program)
            @php($value = $feature['values'][$program['slug']] ?? 'unknown')
            <td @class(['cmp-nonox' => $program['slug'] === 'nonox'])>
              @if(isset($icon[$value]))
                <span class="cmp-cell" title="{{ $comparison['legend'][$value] }}">{!! $icon[$value] !!}<span class="cmp-sr">{{ $comparison['legend'][$value] }}</span>@if($value === 'per')<span class="cmp-note">avgift</span>@elseif($value === 'web')<span class="cmp-note">webbapp</span>@elseif($value === 'self_hosted')<span class="cmp-note">egen drift</span>@elseif($value === 'own_key')<span class="cmp-note">egen AI-nyckel</span>@endif</span>
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
<p class="cmp-note-connect" style="margin:12px 0 0;font-size:13.5px;color:var(--muted)">* NONOX Connect (Peppol, Skatteverket, bank) är ett tillägg som kostar extra: leverantörens pris + 10 %, från 2027-01-11.</p>
<div class="cmp-legend">
  <span>{!! $icon['yes'] !!} Ingår</span>
  <span>{!! $icon['per'] !!} Ingår, med avgift per anställd eller lönebesked</span>
  <span><span class="cmp-pill">Tillval</span> Kostar extra</span>
  <span>{!! $icon['web'] !!}<span class="cmp-note">webbapp</span> Installeras från webbläsaren (PWA), inte från App Store eller Google Play</span>
  <span>{!! $icon['no'] !!} Finns inte</span>
  <span>{!! $icon['self_hosted'] !!}<span class="cmp-note">egen drift</span> Bara när du kör NONOX själv, med eget avtal med en leverantör av öppen bankdata. Erbjuds inte på nonox.app än</span>
  <span>{!! $icon['own_key'] !!}<span class="cmp-note">egen AI-nyckel</span> Kräver företagets egen nyckel hos en AI-leverantör, som tar betalt enligt sitt eget pris</span>
  <span><span class="cmp-unknown">Anges ej</span> Står inte på leverantörens pris- eller produktsidor</span>
</div>
<details class="cmp-sources">
  <summary>Så har vi räknat, och källor</summary>
  <p>{{ $comparison['scenario'] }} Priserna hämtades från leverantörernas webbplatser {{ \Illuminate\Support\Str::of($comparison['fetched_at'])->replace(' ', ' kl. ') }} (svensk tid). Priser ändras; kontrollera alltid hos leverantören.</p>
  <ul>
    @foreach($programs as $program)
      <li><strong>{{ $program['name'] }}:</strong> {{ $program['plan'] }}. {{ $program['price_note'] }}@if($program['source']) <a href="{{ $program['source'] }}" rel="nofollow noopener" target="_blank">Källa</a>@endif</li>
    @endforeach
  </ul>
  <p>Funktionerna gäller det jämförda paketet enligt prissidan och leverantörens egna produktsidor. Står en funktion inte där skriver vi "Anges ej", även om den kan finnas i ett annat paket. NONOX tar bara fram årsredovisning enligt K2, inte K3, och raden om bokslut och årsredovisning gäller det.@isset($comparison['features_checked_at']) Raderna för lager, streckkodsskanning, CRM, API, bankkoppling, schema, inkorg och tolkning kontrollerades {{ \Illuminate\Support\Str::of($comparison['features_checked_at'])->replace(' ', ' kl. ') }}.@endisset</p>
</details>
