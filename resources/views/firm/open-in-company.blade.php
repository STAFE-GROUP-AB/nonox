{{--
    Opens something in its own company: switches company (only to one of the
    user's own, CompanyController::switch) and goes to the page or the task.
    Variables: $companyId, and $destination or $taskId; $label, $class.
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
    @if(! empty($notice))
        <input type="hidden" name="notis" value="{{ $notice }}">
    @endif
    <button type="submit" class="{{ $class ?? 'text-sm font-semibold text-accent-700 hover:text-accent-800' }}">{{ $label }}</button>
</form>
