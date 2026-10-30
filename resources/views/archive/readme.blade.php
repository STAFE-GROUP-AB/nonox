{{-- Plain text, not HTML: written with {!! !!} so names keep their & and quotes. --}}
{!! __('ARKIVPAKET') !!}

{!! $company->name !!} ({!! $company->org_number !!})
{!! __('Räkenskapsår :start – :end', ['start' => $fiscalYear->start_date->toDateString(), 'end' => $fiscalYear->end_date->toDateString()]) !!}
{!! __('Skapat :date av :name', ['date' => $createdAt->format('Y-m-d H:i'), 'name' => $user->name]) !!}

{!! __('Paketet innehåller räkenskapsinformationen för räkenskapsåret och går att läsa utan NONOX: filerna är PDF, CSV (semikolonavgränsad, öppnas i Excel), SIE4, XML och JSON.') !!}

{!! __('Spara paketet i sju år efter utgången av det kalenderår då räkenskapsåret avslutades (bokföringslagen 7 kap.).') !!}

{!! __('INNEHÅLL') !!}

sie/  {!! __('Hela årets bokföring i SIE4-format: kontoplan, balanser och alla bokförda verifikationer (:count fil).', ['count' => $contents['sie']]) !!}
rapporter/  {!! __('Saldobalans, resultatrapport, balansrapport, huvudbok och verifikationslista för hela året, som PDF och CSV (:count filer).', ['count' => $contents['rapporter']]) !!}
verifikationer/  {!! __('Underlagen till verifikationerna, en mapp per verifikation, till exempel A12 (:count filer).', ['count' => $contents['underlag']]) !!}
kundfakturor/  {!! __('Kundfakturor och kreditfakturor som PDF (:count filer).', ['count' => $contents['kundfakturor']]) !!}
leverantorsfakturor/  {!! __('Leverantörsfakturorna som de kom in (:count filer).', ['count' => $contents['leverantorsfakturor']]) !!}
moms/  {!! __('Momsdeklarationerna: rutor och belopp per period samt filen till Skatteverket (:count filer).', ['count' => $contents['moms']]) !!}
lon/  {!! __('Lönebesked och arbetsgivardeklarationer för lön som betalats ut under året (:count filer).', ['count' => $contents['lon']]) !!}
behandlingshistorik/  {!! __('Allt som gjorts i företaget från årets första dag tills paketet skapades: vem, när och vad (:count filer).', ['count' => $contents['behandlingshistorik']]) !!}
systemdokumentation/  {!! __('Hur systemet fungerar, i den version som gällde när paketet skapades (:count filer).', ['count' => $contents['systemdokumentation']]) !!}
manifest.json  {!! __('Förteckning över alla filer med storlek och SHA-256.') !!}
SHA256SUMS.txt  {!! __('Samma kontrollsummor i ett format som datorn kan kontrollera.') !!}

{!! __('KONTROLLERA ATT INGET ÄNDRATS') !!}

{!! __('Öppna en terminal i mappen och skriv:') !!}
  macOS:   shasum -a 256 -c SHA256SUMS.txt
  Linux:   sha256sum -c SHA256SUMS.txt
  Windows: Get-FileHash -Algorithm SHA256 <fil>  ({!! __('jämför med SHA256SUMS.txt') !!})
{!! __('Varje fil ska svara OK. En fil som svarar FAILED har ändrats efter att paketet skapades.') !!}
