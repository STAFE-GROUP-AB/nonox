@php
    $months = [1 => __('januari'), __('februari'), __('mars'), __('april'), __('maj'), __('juni'), __('juli'), __('augusti'), __('september'), __('oktober'), __('november'), __('december')];
    $today = now()->toDateString();
    $yesterday = now()->subDay()->toDateString();
    $dayLabel = function (string $date) use ($months, $today, $yesterday): string {
        if ($date === $today) {
            return __('I dag');
        }

        if ($date === $yesterday) {
            return __('I går');
        }

        $day = \Carbon\CarbonImmutable::parse($date);

        return $day->day.' '.$months[$day->month];
    };
    $initials = fn (string $name): string => mb_strtoupper(collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->take(2)->map(fn (string $word): string => mb_substr($word, 0, 1))->implode(''));
@endphp
<section class="overflow-hidden rounded-[28px] bg-white shadow-sm ring-1 ring-stone-200" aria-labelledby="firm-activity-title">
    <header class="flex flex-wrap items-end justify-between gap-4 border-b border-stone-200 bg-paper px-6 py-6 sm:px-8">
        <div>
            <p class="text-sm text-gray-500">{{ __('Din redovisningsbyrå') }}</p>
            <h2 id="firm-activity-title" class="mt-1 text-2xl font-bold tracking-tight text-ink">{{ __('Vad byrån har gjort') }}</h2>
        </div>
        <div class="flex items-center gap-3">
            <span class="flex size-10 items-center justify-center rounded-xl bg-ink text-sm font-bold text-white" aria-hidden="true">{{ $initials($firm['responsible'] ?? $firm['consultants'][0]) }}</span>
            <div class="text-sm">
                <p class="text-gray-500">{{ $firm['responsible'] ? __('Ansvarig konsult') : __('Konsulter') }}</p>
                <p class="font-semibold text-ink">{{ $firm['responsible'] ?? implode(', ', $firm['consultants']) }}</p>
            </div>
        </div>
    </header>

    @if($firm['days'] === [])
        <p class="px-6 py-8 text-sm text-gray-600 sm:px-8">{{ __('Byrån har inte gjort något i företaget de senaste :days dagarna.', ['days' => \App\Domain\Company\FirmActivity::DAYS]) }}</p>
    @else
        <ol class="divide-y divide-stone-200">
            @foreach(array_slice($firm['days'], 0, 10) as $day)
                <li class="grid gap-2 px-6 py-4 sm:grid-cols-[9rem_1fr] sm:px-8">
                    <p class="text-sm font-semibold text-ink">{{ $dayLabel($day['date']) }}</p>
                    <ul class="space-y-1.5 text-sm">
                        @foreach($day['entries'] as $entry)
                            <li class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex min-w-10 justify-center rounded-full bg-stone-100 px-2.5 py-0.5 font-semibold tabular-nums text-ink">{{ $entry['count'] }}</span>
                                <span class="text-gray-900">{{ $entry['label'] }}</span>
                                @if(count($firm['consultants']) > 1)
                                    <span class="text-gray-500">· {{ $entry['name'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ol>
        <p class="border-t border-stone-200 px-6 py-4 text-xs text-gray-500 sm:px-8">{{ __('Hämtat ur behandlingshistoriken, de senaste :days dagarna.', ['days' => \App\Domain\Company\FirmActivity::DAYS]) }}</p>
    @endif
</section>
