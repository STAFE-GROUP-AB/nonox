@php
    $tab = 'manad';
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-xl shadow-sm';
    $short = [1 => __('Jan'), __('Feb'), __('Mar'), __('Apr'), __('Maj'), __('Jun'), __('Jul'), __('Aug'), __('Sep'), __('Okt'), __('Nov'), __('Dec')];
    $long = [1 => __('januari'), __('februari'), __('mars'), __('april'), __('maj'), __('juni'), __('juli'), __('augusti'), __('september'), __('oktober'), __('november'), __('december')];
    [$year, $monthNumber] = array_map('intval', explode('-', $selected));
    $states = [
        'REPORTED' => [__('Lämnad'), 'neutral'],
        'DUE' => [__('Att göra'), 'todo'],
        'LATE' => [__('Försenad'), 'late'],
        'OPEN' => [__('Pågår'), 'neutral'],
        'QUARTER' => [__('Kvartal'), 'neutral'],
        'YEAR' => [__('Helår'), 'neutral'],
        'NONE' => [__('Ej aktuell'), 'neutral'],
    ];
    $pill = fn (string $tone): string => match ($tone) {
        'late' => 'bg-signal-500 text-white',
        'todo' => 'bg-signal-100 text-signal-800',
        default => 'bg-stone-100 text-gray-800',
    };
    $kronor = fn (int $ore): string => number_format(intdiv($ore, 100), 0, ',', "\u{a0}")."\u{a0}kr";
    // Two letters for the avatar: the first letters of the first two real words, or the first two letters of a single word.
    $initials = function (string $name): string {
        $words = array_values(array_filter(preg_split('/[\s\-]+/u', trim($name)) ?: [], fn (string $word): bool => mb_strlen($word) > 2 && ! in_array(mb_strtoupper($word), ['AB', 'HB', 'KB', 'EF'], true)));

        return mb_strtoupper(match (count($words)) {
            0 => mb_substr(trim($name), 0, 2),
            1 => mb_substr($words[0], 0, 2),
            default => mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1),
        });
    };
@endphp
<div class="space-y-6">
    @include('livewire.company.overview-tabs')

    <div class="max-w-md">
        <label for="company_search_months" class="sr-only">{{ __('Sök klient') }}</label>
        <input id="company_search_months" type="search" class="{{ $input }}" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök klient på namn eller organisationsnummer') }}">
    </div>

    <section class="overflow-hidden rounded-[28px] bg-white shadow-sm ring-1 ring-stone-200">
        <header class="flex flex-wrap items-end justify-between gap-4 border-b border-stone-200 bg-paper px-6 py-6 sm:px-8">
            <div>
                <p class="text-sm text-gray-500">{{ __('Byråvy') }} · {{ $firm }}</p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">{{ __('Klienter, :month :year', ['month' => $long[$monthNumber], 'year' => $year]) }}</h2>
            </div>
            <nav class="flex flex-wrap gap-2" aria-label="{{ __('Välj månad') }}">
                @foreach($months as $option)
                    @php([, $optionMonth] = array_map('intval', explode('-', $option)))
                    <button type="button" wire:click="$set('month', '{{ $option }}')" @class([
                        'rounded-full px-4 py-2 text-sm font-semibold transition',
                        'bg-ink text-white' => $option === $selected,
                        'bg-stone-200/70 text-gray-800 hover:bg-stone-200' => $option !== $selected,
                    ]) @if($option === $selected) aria-current="true" @endif>{{ $short[$optionMonth] }}</button>
                @endforeach
            </nav>
        </header>

        @if($rows === [])
            <div class="px-8 py-12 text-center">
                <p class="font-semibold text-gray-900">{{ __('Inga klienter att visa') }}</p>
                <p class="mt-1 text-sm text-gray-600">{{ $search === '' ? __('Du är inte medlem i något företag ännu.') : __('Sök på en del av namnet eller organisationsnumret.') }}</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-left">
                    <thead>
                        <tr class="border-b border-stone-200 text-xs font-medium uppercase tracking-[0.12em] text-gray-500">
                            <th scope="col" class="px-6 py-4 font-medium sm:px-8">{{ __('Klient') }}</th>
                            <th scope="col" class="px-4 py-4 font-medium">{{ __('Bokfört t.o.m.') }}</th>
                            <th scope="col" class="px-4 py-4 font-medium" title="{{ __('Inlästa bankposter som ännu inte är kopplade till en faktura.') }}">{{ __('Bankavstämning') }}</th>
                            <th scope="col" class="px-4 py-4 font-medium">{{ __('Moms') }}</th>
                            <th scope="col" class="px-4 py-4 font-medium">{{ __('AGI') }}</th>
                            <th scope="col" class="px-6 py-4 text-right font-medium sm:px-8">{{ __('Obetalda kundfakt.') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        @foreach($rows as $row)
                            @php($company = $row['company'])
                            @php($cell = $row['cells'][$selected])
                            <tr wire:key="client-{{ $company->id }}" class="align-middle hover:bg-stone-50/60">
                                <td class="px-6 py-5 sm:px-8">
                                    <form method="POST" action="{{ route('current-team.update') }}" class="flex">
                                        @method('PUT')
                                        @csrf
                                        <input type="hidden" name="team_id" value="{{ $company->id }}">
                                        <input type="hidden" name="till" value="dashboard">
                                        <button type="submit" class="flex items-center gap-4 text-left">
                                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-stone-100 text-sm font-bold text-ink" aria-hidden="true">{{ $initials($company->name) }}</span>
                                            <span class="font-semibold text-ink hover:underline">{{ $company->name }}</span>
                                        </button>
                                    </form>
                                </td>
                                <td class="whitespace-nowrap px-4 py-5 tabular-nums tracking-[0.08em] text-gray-900">{{ $cell['posted_until'] ?? '–' }}</td>
                                <td class="px-4 py-5">
                                    <div class="flex">
                                    @if($cell['bank'] === null)
                                        <span class="inline-flex rounded-full px-3 py-1 text-sm font-medium {{ $pill('neutral') }}">{{ __('Ej aktuell') }}</span>
                                    @elseif($cell['bank'] === 0)
                                        <span class="inline-flex rounded-full px-3 py-1 text-sm font-medium {{ $pill('neutral') }}">{{ __('Klar') }}</span>
                                    @else
                                        <span class="inline-flex whitespace-nowrap rounded-full px-3 py-1 text-sm font-medium {{ $pill('todo') }}">{{ $cell['bank'] === 1 ? __('1 post') : __(':count poster', ['count' => $cell['bank']]) }}</span>
                                    @endif
                                    </div>
                                </td>
                                @foreach(['vat' => 'vat', 'agi' => 'agi'] as $key => $destination)
                                    <td class="px-4 py-5">
                                        @if($cell[$key] !== null)
                                            @php([$text, $tone] = $states[$cell[$key]])
                                            @php($due = $cell[$key.'_due'])
                                            @if(in_array($cell[$key], ['DUE', 'LATE'], true))
                                                <form method="POST" action="{{ route('current-team.update') }}" class="flex">
                                                    @method('PUT')
                                                    @csrf
                                                    <input type="hidden" name="team_id" value="{{ $company->id }}">
                                                    <input type="hidden" name="till" value="{{ $destination }}">
                                                    <button type="submit" class="inline-flex whitespace-nowrap rounded-full px-3 py-1 text-sm font-medium {{ $pill($tone) }}" title="{{ $due ? __('Sista dag :date', ['date' => $due]) : '' }}">{{ $text }}</button>
                                                </form>
                                            @else
                                                <div class="flex"><span class="inline-flex whitespace-nowrap rounded-full px-3 py-1 text-sm font-medium {{ $pill($tone) }}">{{ $text }}</span></div>
                                            @endif
                                        @else
                                            <span class="text-gray-400">–</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="whitespace-nowrap px-6 py-5 text-right font-semibold tabular-nums text-ink sm:px-8">{{ $cell['receivables'] === null ? '–' : $kronor($cell['receivables']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
