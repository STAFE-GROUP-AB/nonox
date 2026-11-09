{{-- One incident on the public status page. Text is escaped; line breaks are kept. --}}
<article class="incident" data-incident="{{ $incident['id'] }}">
  <h3>{{ $incident['title'] }}</h3>
  <p style="margin:4px 0 8px;font-size:14px">{{ $incident['severity_label'] }} · {{ $incident['status_label'] }} · började <time>{{ $incident['started'] }}</time>@if($incident['resolved']) · åtgärdad <time>{{ $incident['resolved'] }}</time>@endif</p>
  <ul style="margin:0;padding-left:20px">
    @foreach($incident['updates'] as $update)
      <li><time>{{ $update['at'] }}</time> <strong style="color:var(--ink)">{{ $update['status'] }}.</strong> {!! nl2br(e($update['body'])) !!}</li>
    @endforeach
  </ul>
</article>
