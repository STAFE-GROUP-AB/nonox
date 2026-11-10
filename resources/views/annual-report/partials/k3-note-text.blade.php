@if($preview && $note->overridden())
    <p class="override" data-override="{{ $note->key }}">{{ $note->stale ? __('Egen formulering. Bokföringen har ändrats sedan texten skrevs; läs igenom den igen.') : __('Egen formulering.') }}</p>
@endif
@foreach($note->text() as $paragraph)
    <p>{!! nl2br(e($paragraph)) !!}</p>
@endforeach
