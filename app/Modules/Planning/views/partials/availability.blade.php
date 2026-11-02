{{--
    What the person said about the day in Min sida: can, cannot or would
    rather work. Read-only; it never blocks a plan.
--}}
@if($mark)
    <span
        data-availability="{{ $mark['kind'] }}"
        title="{{ $mark['text'] }}"
        @class([
            'pointer-events-none mb-1 inline-block max-w-full truncate rounded px-1.5 text-[0.7rem] font-semibold leading-5',
            'bg-red-50 text-red-800' => $mark['kind'] === App\Modules\Planning\Models\Availability::UNAVAILABLE,
            'bg-green-50 text-green-800' => $mark['kind'] === App\Modules\Planning\Models\Availability::PREFERRED,
            'bg-stone-100 text-gray-700' => $mark['kind'] === App\Modules\Planning\Models\Availability::AVAILABLE,
        ])
    >{{ match ($mark['kind']) { App\Modules\Planning\Models\Availability::UNAVAILABLE => __('Kan inte'), App\Modules\Planning\Models\Availability::PREFERRED => __('Gärna'), default => __('Kan') } }}<span class="sr-only">: {{ $mark['text'] }}</span></span>
@endif
