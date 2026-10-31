{{--
    Cookie consent (CookieInventory). Accept and reject are equally prominent;
    the choice is kept in localStorage for 12 months together with the
    inventory version, and window.nonoxConsent.allows(category) tells other
    scripts what they may load. Any [data-cookie-settings] link reopens it.
--}}
@php($categories = App\Support\Cookies\CookieInventory::categories())
{{-- hidden must win over the inline display on every page, also those without the public stylesheet. --}}
<style>#nx-consent[hidden],#nx-consent [hidden]{display:none!important}</style>
<div id="nx-consent" role="dialog" aria-modal="false" aria-labelledby="nx-consent-title" hidden style="position:fixed;left:16px;right:16px;bottom:16px;z-index:200;display:flex;justify-content:center;pointer-events:none;font-family:Inter,system-ui,sans-serif">
  <div style="pointer-events:auto;width:100%;max-width:760px;max-height:calc(100vh - 32px);overflow-y:auto;background:#15171C;color:#F7F5F0;border-radius:22px;padding:22px 24px;box-shadow:0 30px 60px -20px rgba(0,0,0,.45)">
    <p id="nx-consent-title" style="margin:0;font-weight:700;font-size:16px">Cookies på NONOX</p>
    <p style="margin:8px 0 0;font-size:14.5px;line-height:1.55;color:#D9D7D1">Vi använder bara cookies som behövs för att sidan och inloggningen ska fungera. Ingen spårning, ingen annonsering. Du kan ändra ditt val när du vill. <a href="{{ route('cookies') }}" style="color:#FF8A6D">Läs mer om cookies</a></p>
    <form id="nx-consent-settings" hidden style="margin-top:14px;display:flex;flex-direction:column;gap:10px">
      @foreach($categories as $category)
        <label style="display:flex;gap:12px;align-items:flex-start;padding:12px 14px;border-radius:14px;background:rgba(255,255,255,.06);font-size:14px;line-height:1.5">
          <input type="checkbox" name="{{ $category['key'] }}" @checked($category['required']) @disabled($category['required']) style="margin-top:3px;width:18px;height:18px;accent-color:#FF5A36">
          <span><strong>{{ $category['name'] }}</strong>@if($category['required']) · alltid på @endif<br><span style="color:#D9D7D1">{{ $category['description'] }}</span></span>
        </label>
      @endforeach
    </form>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:16px">
      <button type="button" data-consent="necessary" style="flex:1 1 180px;min-height:46px;border-radius:999px;border:1.5px solid #F7F5F0;background:transparent;color:#F7F5F0;font:700 15px Inter,system-ui,sans-serif;cursor:pointer">Endast nödvändiga</button>
      <button type="button" data-consent="all" style="flex:1 1 180px;min-height:46px;border-radius:999px;border:1.5px solid #F7F5F0;background:#F7F5F0;color:#15171C;font:700 15px Inter,system-ui,sans-serif;cursor:pointer">Tillåt alla</button>
      <button type="button" data-consent="settings" style="flex:1 1 140px;min-height:46px;border-radius:999px;border:0;background:transparent;color:#F7F5F0;text-decoration:underline;font:600 15px Inter,system-ui,sans-serif;cursor:pointer">Inställningar</button>
      <button type="button" data-consent="save" hidden style="flex:1 1 180px;min-height:46px;border-radius:999px;border:0;background:#FF5A36;color:#15171C;font:700 15px Inter,system-ui,sans-serif;cursor:pointer">Spara mitt val</button>
    </div>
  </div>
</div>
<script>
(() => {
    const key = 'nonox-consent';
    const version = {{ App\Support\Cookies\CookieInventory::VERSION }};
    const optional = @json(array_values(array_map(fn ($c) => $c['key'], array_filter($categories, fn ($c) => ! $c['required']))));
    const year = 365 * 24 * 3600 * 1000;
    const banner = document.getElementById('nx-consent');
    const settings = document.getElementById('nx-consent-settings');
    const save = banner.querySelector('[data-consent="save"]');
    const read = () => { try { const c = JSON.parse(localStorage.getItem(key) || 'null'); return c && c.version === version && Date.now() - c.at < year ? c : null; } catch (e) { return null; } };
    const store = (granted) => { try { localStorage.setItem(key, JSON.stringify({ version, at: Date.now(), granted })); } catch (e) { /* private window: asked again next visit */ } window.nonoxConsent.granted = granted; banner.hidden = true; document.dispatchEvent(new CustomEvent('nonox:consent', { detail: granted })); };
    window.nonoxConsent = { granted: (read() || { granted: [] }).granted, allows: (category) => category === 'necessary' || window.nonoxConsent.granted.includes(category), open: () => { banner.hidden = false; settings.hidden = false; save.hidden = false; const c = read(); settings.querySelectorAll('input:not([disabled])').forEach((box) => { box.checked = !!c && c.granted.includes(box.name); }); } };
    banner.querySelector('[data-consent="necessary"]').addEventListener('click', () => store([]));
    banner.querySelector('[data-consent="all"]').addEventListener('click', () => store(optional));
    banner.querySelector('[data-consent="settings"]').addEventListener('click', () => { settings.hidden = !settings.hidden; save.hidden = settings.hidden; });
    save.addEventListener('click', () => store([...settings.querySelectorAll('input:not([disabled]):checked')].map((box) => box.name)));
    document.addEventListener('click', (event) => { if (event.target.closest('[data-cookie-settings]')) { event.preventDefault(); window.nonoxConsent.open(); } });
    if (!read()) { banner.hidden = false; }
})();
</script>
