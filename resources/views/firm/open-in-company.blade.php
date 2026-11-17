{{--
    Opens something in its own company: switches company (only to one of the
    user's own, CompanyController::switch) and goes to the page or the task.
    Variables: $companyId, and $destination or $taskId; $label, $class.
    Optional: $week and $day (YYYY-MM-DD) for the planning schedule, $title.
--}}
<form method="POST" action="{{ route('current-team.update') }}" class="contents">
    @method('PUT')
    @csrf
    <input type="hidden" name="team_id" value="{{ $companyId }}">
    @if(! empty($taskId))
        <input type="hidden" name="uppgift" value="{{ $taskId }}">
    @else
        <input type="hidden" name="till" value="{{ $destination ?? 'dashboard' }}">
    @endif
    @if(! empty($week))
        <input type="hidden" name="vecka" value="{{ $week }}">
    @endif
    @if(! empty($day))
        <input type="hidden" name="dag" value="{{ $day }}">
    @endif
    @if(! empty($notice))
        <input type="hidden" name="notis" value="{{ $notice }}">
    @endif
    <button type="submit" @if(! empty($title)) title="{{ $title }}" @endif class="{{ $class ?? 'text-sm font-semibold text-accent-700 hover:text-accent-800' }}">{{ $label }}</button>
</form>
