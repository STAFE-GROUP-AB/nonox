@php
    $kr = fn (?int $ore): string => $ore === null ? '–' : number_format($ore / 100, 2, ',', "\u{a0}")."\u{a0}kr";
    $connected = $connection?->isConnected() ?? false;
    $done = $connection?->completed_at !== null;
    $step = fn (int $number, string $title, bool $complete) => ['number' => $number, 'title' => $title, 'complete' => $complete];
    $registers = $connection?->progress['registers'] ?? null;
    $fileTotal = $files->sum();
@endphp
<div class="space-y-6" @if($fetchingFiles) wire:poll.750ms="nextFiles" @endif>
    @unless($configured)
        <x-ui.card>
            <p class="text-sm text-gray-700">{{ __('Hämtning från Fortnox är inte inställd på den här installationen. Den som sköter servern lägger in FORTNOX_CLIENT_ID och FORTNOX_CLIENT_SECRET från Fortnox utvecklarportal. Under tiden fungerar flytten med SIE-filer.') }}
                <a href="{{ route('accounting.sieImports.index') }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Läs in SIE-filer') }}</a></p>
        </x-ui.card>
    @endunless

    <x-ui.card :title="__('1. Anslut till Fortnox')" :description="__('Du loggar in hos Fortnox och godkänner att NONOX får läsa. Kontot måste tillhöra samma organisationsnummer som det här företaget.')">
        <div class="mt-4 flex flex-wrap items-center gap-4">
            @if($done)
                <span class="text-sm text-gray-700">{{ __('Flytten från :name är klar :date. Anslutningen är borttagen.', ['name' => $connection->fortnox_company_name, 'date' => $connection->completed_at->toDateString()]) }}</span>
            @elseif($connected)
                <span class="inline-flex items-center gap-2 text-sm font-medium text-gray-900"><span class="size-2 rounded-full bg-emerald-500"></span>{{ __('Ansluten till :name (:number)', ['name' => $connection->fortnox_company_name, 'number' => $connection->fortnox_org_number]) }}</span>
            @endif
            @if($configured && ! $connected)
                <a href="{{ route('fortnox.connect') }}" class="inline-flex items-center rounded-full bg-ink px-5 py-2.5 text-sm font-semibold text-white hover:bg-gray-800">{{ $done ? __('Anslut igen') : __('Anslut till Fortnox') }}</a>
            @endif
        </div>
    </x-ui.card>

    @if($connected)
        <x-ui.card :title="__('2. Bokföringen, år för år')" :description="__('Varje räkenskapsår hämtas som en SIE4-fil och granskas innan något bokförs, precis som när du läser in en fil själv. Börja med det år som redan finns här, oftast det pågående, och fortsätt sedan bakåt ett år i taget.')">
            @if($years === [])
                <button type="button" wire:click="loadYears" wire:loading.attr="disabled" class="mt-4 inline-flex rounded-full border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-900 hover:border-gray-900">{{ __('Visa räkenskapsåren i Fortnox') }}</button>
            @else
                <ul class="mt-4 divide-y divide-gray-100">
                    @foreach($years as $year)
                        @php($importId = $connection->progress['years'][(string) $year['id']] ?? null)
                        @php($import = $importId ? $imports->get($importId) : null)
                        <li wire:key="year-{{ $year['id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <span class="font-medium tabular-nums text-gray-900">{{ $year['from'] }} – {{ $year['to'] }}</span>
                            <span class="flex items-center gap-3 text-sm">
                                @if($import)
                                    <a href="{{ route('accounting.sieImports.show', $import) }}" class="text-accent-600 hover:text-accent-700">{{ $import->status === App\Domain\Accounting\SieImport\SieImportStatus::Completed ? __('Inläst') : __('Hämtad, granska och läs in') }}</a>
                                @endif
                                <button type="button" wire:click="fetchYear({{ $year['id'] }})" wire:loading.attr="disabled" class="rounded-full border border-gray-300 px-4 py-1.5 font-semibold text-gray-900 hover:border-gray-900">{{ $import ? __('Hämta igen') : __('Hämta') }}</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('3. Kunder, leverantörer och artiklar')" :description="__('Läggs till om de inte redan finns i NONOX. Det som inte går att läsa listas nedan.')">
            <button type="button" wire:click="importRegisters" wire:loading.attr="disabled" class="mt-4 inline-flex rounded-full border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-900 hover:border-gray-900">{{ $registers ? __('Hämta igen') : __('Hämta registren') }}</button>
            @if($registers)
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    @foreach(['customers' => __('Kunder'), 'suppliers' => __('Leverantörer'), 'articles' => __('Artiklar')] as $key => $label)
                        <div class="rounded-xl bg-gray-50 p-4">
                            <dt class="font-medium text-gray-900">{{ $label }}</dt>
                            <dd class="mt-1 text-gray-700">{{ __(':created nya, :existing fanns redan', ['created' => $registers[$key]['created'], 'existing' => $registers[$key]['existing']]) }}</dd>
                            @foreach(array_slice($registers[$key]['failed'], 0, 5) as $failure)
                                <dd class="mt-1 text-xs text-accent-700">{{ $failure }}</dd>
                            @endforeach
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('4. Underlag')" :description="__('Kvitton och fakturor som är kopplade till verifikationerna i Fortnox hämtas och kopplas till samma verifikation i NONOX. Läs in åren i steg 2 först.')">
            <div class="mt-4 flex flex-wrap items-center gap-4">
                <button type="button" wire:click="startFiles" wire:loading.attr="disabled" @disabled($fetchingFiles) class="inline-flex rounded-full border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-900 hover:border-gray-900">{{ $fileTotal > 0 ? __('Hämta resten') : __('Hämta underlag') }}</button>
                @if($fileTotal > 0)
                    <span class="text-sm tabular-nums text-gray-700">{{ __(':done av :total hämtade', ['done' => $files->get('DONE', 0), 'total' => $fileTotal]) }}@if($files->get('FAILED', 0) > 0) · {{ __(':failed gick inte', ['failed' => $files->get('FAILED')]) }}@endif</span>
                    <span class="h-2 w-48 overflow-hidden rounded-full bg-gray-100"><span class="block h-full bg-accent-500" style="width: {{ $fileTotal ? round(100 * ($files->get('DONE', 0) + $files->get('FAILED', 0)) / $fileTotal) : 0 }}%"></span></span>
                @endif
            </div>
            @if($failedFiles->isNotEmpty())
                <ul class="mt-3 space-y-1 text-xs text-accent-700">
                    @foreach($failedFiles as $failed)
                        <li>{{ $failed->voucher_series }} {{ $failed->voucher_number }} · {{ $failed->name }}: {{ $failed->message }}</li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('5. Stäm av obetalda fakturor')" :description="__('Obetalda kund- och leverantörsfakturor i Fortnox jämförs med kundfordringar (1510) och leverantörsskulder (2440) i NONOX:s senaste år.')">
            <button type="button" wire:click="compareOpenItems" wire:loading.attr="disabled" class="mt-4 inline-flex rounded-full border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-900 hover:border-gray-900">{{ __('Jämför') }}</button>
            @if($openItems)
                <table class="mt-4 w-full text-sm tabular-nums">
                    <thead><tr class="text-left text-gray-500"><th class="pb-2 font-medium"></th><th class="pb-2 text-right font-medium">{{ __('Obetalda i Fortnox') }}</th><th class="pb-2 text-right font-medium">{{ __('Saldo i NONOX') }}</th><th class="pb-2 text-right font-medium"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach(['customers' => __('Kundfakturor (1510)'), 'suppliers' => __('Leverantörsfakturor (2440)')] as $key => $label)
                            @php($row = $openItems[$key])
                            <tr>
                                <td class="py-2 font-medium text-gray-900">{{ $label }} <span class="font-normal text-gray-500">· {{ $row['count'] }}</span></td>
                                <td class="py-2 text-right">{{ $kr($row['fortnox']) }}</td>
                                <td class="py-2 text-right">{{ $kr($row['nonox']) }}</td>
                                <td class="py-2 text-right font-medium {{ $row['nonox'] === $row['fortnox'] ? 'text-gray-900' : 'text-accent-700' }}">{{ $row['nonox'] === $row['fortnox'] ? __('Stämmer') : __('Skiljer sig') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500">{{ __('Skiljer det sig, kontrollera att det senaste året är inläst och att inga fakturor har betalats efter exporten.') }}</p>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('6. Klart')" :description="__('När allt stämmer avslutar du flytten. Anslutningen till Fortnox tas bort och nycklarna raderas. Spara ditt Fortnox-arkiv i sju år, som Bokföringslagen kräver.')">
            <x-button type="button" class="mt-4" wire:click="complete" wire:confirm="{{ __('Avsluta flytten och ta bort anslutningen till Fortnox? Nycklarna raderas och inget mer kan hämtas utan att du ansluter igen.') }}">{{ __('Avsluta flytten') }}</x-button>
        </x-ui.card>
    @endif
</div>
