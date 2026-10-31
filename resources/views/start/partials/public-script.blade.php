{{-- Menu, copy button, price calculator and scroll effects of the public pages. --}}
<script>
(() => {
    const plans = @json($calculatorPlans ?? []);
    const kr = (n) => n.toLocaleString('sv-SE').replace(/\s/g, ' ') + ' kr';

    const menuButton = document.querySelector('[data-lp-menu]');
    const menu = document.getElementById('lp-mobilmeny');
    const setMenu = (open) => {
        menu.hidden = !open;
        menuButton.setAttribute('aria-expanded', String(open));
        menuButton.setAttribute('aria-label', open ? 'Stäng meny' : 'Öppna meny');
        menuButton.querySelector('path').setAttribute('d', open ? 'M6 6l12 12M18 6L6 18' : 'M4 7h16M4 12h16M4 17h16');
    };
    menuButton?.addEventListener('click', () => setMenu(menu.hidden));
    document.querySelectorAll('[data-lp-close]').forEach((link) => link.addEventListener('click', () => setMenu(false)));

    // Mega menu: opens on click or hover, closes on Esc, outside click, leaving the header or choosing a link.
    const header = document.getElementById('lp-header');
    const triggers = [...document.querySelectorAll('[data-mm]')];
    let closeTimer;
    const openMenu = (key) => {
        clearTimeout(closeTimer);
        triggers.forEach((trigger) => {
            const open = trigger.dataset.mm === key;
            trigger.setAttribute('aria-expanded', String(open));
            document.getElementById('mm-' + trigger.dataset.mm).hidden = !open;
        });
    };
    const closeMenus = () => openMenu(null);
    const canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => trigger.getAttribute('aria-expanded') === 'true' ? closeMenus() : openMenu(trigger.dataset.mm));
        if (canHover) {
            trigger.addEventListener('mouseenter', () => { closeTimer = setTimeout(() => openMenu(trigger.dataset.mm), 80); });
            trigger.addEventListener('mouseleave', () => clearTimeout(closeTimer));
        }
    });
    if (canHover) {
        header.addEventListener('mouseleave', () => { closeTimer = setTimeout(closeMenus, 180); });
        header.addEventListener('mouseenter', () => clearTimeout(closeTimer));
    }
    document.querySelectorAll('[data-mm-close]').forEach((link) => link.addEventListener('click', closeMenus));
    document.addEventListener('click', (event) => { if (!header.contains(event.target)) { closeMenus(); } });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') { return; }
        const open = triggers.find((trigger) => trigger.getAttribute('aria-expanded') === 'true');
        if (open) { closeMenus(); open.focus(); }
    });

    document.querySelectorAll('[data-lp-copy]').forEach((button) => button.addEventListener('click', () => {
        const label = button.querySelector('[data-lp-copy-label]');
        const done = () => { label.textContent = 'Kopierat'; setTimeout(() => { label.textContent = 'Kopiera'; }, 2000); };
        navigator.clipboard ? navigator.clipboard.writeText(button.dataset.lpCopy).then(done, done) : done();
    }));

    const range = document.querySelector('[data-lp-employees]');
    const show = (name, value) => { const el = document.querySelector('[data-lp-' + name + ']'); if (el) { el.textContent = value; } };
    range?.addEventListener('input', () => {
        const employees = Number(range.value);
        const plan = plans.find((candidate) => employees <= candidate.max);
        range.setAttribute('aria-valuetext', employees > 25 ? '26 eller fler anställda' : employees + ' anställda');
        show('label', employees > 25 ? '26+' : String(employees));
        document.querySelector('[data-lp-ok]').hidden = !plan;
        document.querySelector('[data-lp-contact]').hidden = !!plan;
        if (!plan) { return; }
        const compare = 12 * (plan.compareBase + (employees > 1 ? plan.comparePerEmployee * employees : 0));
        show('plan', plan.name);
        show('price', kr(plan.price) + '/år');
        show('compare', kr(compare) + '/år');
        show('saving', kr(Math.round((compare - plan.price) / 100) * 100));
    });

    const mock = document.getElementById('lp-mock');
    const inner = document.getElementById('lp-mock-inner');
    const scale = () => { const s = Math.min(1, mock.clientWidth / 1000); inner.style.transform = 'scale(' + s + ')'; mock.style.height = Math.round(650 * s) + 'px'; };
    if (mock && inner) { scale(); window.ResizeObserver ? new ResizeObserver(scale).observe(mock) : window.addEventListener('resize', scale); }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

    // The ring of the logo opens once.
    document.querySelectorAll('[data-ring]').forEach((path) => {
        const d = (h) => { const o = Math.sqrt(62500 - h * h), i = Math.sqrt(27889 - h * h);
            return `M${404 + o} ${447 - h} A250 250 0 1 0 ${404 + o} ${447 + h} L${404 + i} ${447 + h} A167 167 0 1 1 ${404 + i} ${447 - h} Z`; };
        const start = performance.now() + 250;
        path.setAttribute('d', d(0.5));
        const step = (now) => { const t = Math.min(1, Math.max(0, (now - start) / 900));
            path.setAttribute('d', d(Math.max(0.5, 19 * (1 - Math.pow(1 - t, 3))))); if (t < 1) { requestAnimationFrame(step); } };
        requestAnimationFrame(step);
    });

    // Sections below the fold fade in as they are scrolled to.
    if (!window.IntersectionObserver) { return; }
    const observer = new IntersectionObserver((entries) => entries.forEach((entry) => {
        if (entry.isIntersecting) { entry.target.style.opacity = '1'; entry.target.style.translate = '0 0'; observer.unobserve(entry.target); }
    }), { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    document.querySelectorAll('[data-reveal]').forEach((el) => {
        if (el.getBoundingClientRect().top < window.innerHeight) { return; }
        el.style.opacity = '0';
        el.style.translate = '0 24px';
        el.style.transition = (el.style.transition ? el.style.transition + ',' : '') + 'opacity .7s ease, translate .7s cubic-bezier(.2,.7,.2,1)';
        observer.observe(el);
    });
})();
</script>
