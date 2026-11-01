{{-- The planned webhook events from the spec's top-level `webhooks`, for the guide Webhooks. --}}
@use('App\Support\Developer\DeveloperPortal', 'Dev')
<div class="dv-table" tabindex="0">
  <table>
    <thead><tr><th scope="col">{{ Dev::t('event', $lang) }}</th><th scope="col">{{ Dev::t('meaning', $lang) }}</th><th scope="col">{{ Dev::t('status', $lang) }}</th></tr></thead>
    <tbody>
      @foreach($spec['webhooks'] as $event => $webhook)
        <tr>
          <th scope="row" style="font-weight:600"><a href="#{{ $webhook['id'] }}"><code>{{ $event }}</code></a></th>
          <td>{{ $webhook['summary'][$lang] }}</td>
          <td>@if($webhook['status'] === 'planned')<span class="dv-planned">{{ Dev::t('planned', $lang) }}</span>@else – @endif</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
<div class="dv-webhooks">
  @foreach($spec['webhooks'] as $webhook)
    @include('developers.partials.operation', ['operation' => $webhook, 'webhook' => true])
  @endforeach
</div>
