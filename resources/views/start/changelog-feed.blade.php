{{-- RSS 2.0 feed of the public changelog, newest first; ChangelogController::feed() puts the XML declaration in front. --}}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title>NONOX ändringslogg</title>
    <link>{{ route('changelog') }}</link>
    <atom:link href="{{ route('changelog.feed') }}" rel="self" type="application/rss+xml"/>
    <description>Nya funktioner, förbättringar och rättelser i NONOX, på vanlig svenska.</description>
    <language>sv-SE</language>
    <lastBuildDate>{{ $updated->toRfc2822String() }}</lastBuildDate>
@foreach($entries as $entry)
    <item>
      <title>{{ $entry['typ'] }}: {{ $entry['rubrik'] }}</title>
      <link>{{ route('changelog') }}#{{ $entry['id'] }}</link>
      <guid isPermaLink="false">nonox-andringslogg-{{ $entry['id'] }}</guid>
      <pubDate>{{ App\Support\Landing\Changelog::date($entry)->toRfc2822String() }}</pubDate>
      <category>{{ $entry['omrade'] }}</category>
      <description>{{ $entry['beskrivning'] }}</description>
    </item>
@endforeach
  </channel>
</rss>
