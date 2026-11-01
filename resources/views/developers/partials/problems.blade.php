{{-- Every problem code from the spec's shared responses, for the guide Fel. --}}
@use('App\Support\Developer\DeveloperPortal', 'Dev')
<div class="dv-table" tabindex="0">
  <table>
    <thead><tr><th scope="col">{{ Dev::t('status', $lang) }}</th><th scope="col">{{ Dev::t('code', $lang) }}</th><th scope="col">{{ Dev::t('meaning', $lang) }}</th></tr></thead>
    <tbody>
      @foreach($spec['problems'] as $problem)
        <tr id="problem-{{ Illuminate\Support\Str::slug($problem['code']) }}">
          <td><span class="dv-status dv-s{{ substr((string) $problem['status'], 0, 1) }}">{{ $problem['status'] }}</span></td>
          <th scope="row" style="font-weight:600"><code>{{ $problem['code'] }}</code></th>
          <td>
            {{ $problem['summary'] }}
            <span style="display:block;margin-top:4px;color:var(--muted)" lang="sv">detail: ”{{ $problem['detail'] }}”</span>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
