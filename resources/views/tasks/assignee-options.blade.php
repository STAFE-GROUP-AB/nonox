{{-- The people a task can go to, grouped as the firm and the client ($people from App\Domain\Tasks\TaskPeople::of). --}}
<option value="">{{ __('Ingen ännu') }}</option>
@if($people['firm'] !== [])
    <optgroup label="{{ __('Byrån') }}">
        @foreach($people['firm'] as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
        @endforeach
    </optgroup>
@endif
@if($people['client'] !== [])
    <optgroup label="{{ $people['firm'] === [] ? __('I företaget') : __('Kunden') }}">
        @foreach($people['client'] as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
        @endforeach
    </optgroup>
@endif
