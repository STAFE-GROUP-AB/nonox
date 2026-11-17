{{--
    Approved absence across the day, or a requested one (managers only).
    No cause is ever shown or loaded (AbsenceDays): the type of an absence
    is health data. Read-only; it never blocks a plan.
--}}
@if($mark)
    @php($label = match ($mark) { App\Modules\Planning\Support\AbsenceDays::FULL => __('Frånvarande'), App\Modules\Planning\Support\AbsenceDays::PARTIAL => __('Delvis frånvarande'), default => __('Frånvaro begärd') })
    <span
        data-absence="{{ $mark }}"
        title="{{ $label }}"
        @class([
            'pointer-events-none mb-1 inline-block max-w-full truncate rounded px-1.5 text-[0.7rem] font-semibold leading-5',
            'bg-stone-200 text-stone-800' => $mark === App\Modules\Planning\Support\AbsenceDays::FULL,
            'bg-amber-100 text-amber-900' => $mark === App\Modules\Planning\Support\AbsenceDays::PARTIAL,
            'bg-white text-gray-600 ring-1 ring-stone-300' => $mark === App\Modules\Planning\Support\AbsenceDays::REQUESTED,
        ])
    >{{ $label }}</span>
@endif
