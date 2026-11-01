{{-- A task's status as a chip: $status (App\Domain\Tasks\TaskStatus). --}}
<span @class([
    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-stone-100 text-gray-800' => $status === App\Domain\Tasks\TaskStatus::Todo,
    'bg-sky-50 text-sky-800' => $status === App\Domain\Tasks\TaskStatus::InProgress,
    'bg-amber-50 text-amber-800' => $status === App\Domain\Tasks\TaskStatus::WaitingForClient,
    'bg-green-50 text-green-800' => $status === App\Domain\Tasks\TaskStatus::Done,
])>{{ $status->label() }}</span>
