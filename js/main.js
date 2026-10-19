// ===== Novis — interacciones =====
(function () {
  const nav = document.getElementById('nav');
  const toggle = document.getElementById('navToggle');
  const menu = document.getElementById('menu');

  // Borde de la barra al hacer scroll
  const onScroll = () => nav.classList.toggle('is-scrolled', window.scrollY > 10);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // Menú móvil
  const closeMenu = () => {
    menu.classList.remove('is-open');
    nav.classList.remove('menu-open');
    toggle.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  };
  toggle.addEventListener('click', () => {
    const open = menu.classList.toggle('is-open');
    nav.classList.toggle('menu-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    document.body.style.overflow = open ? 'hidden' : '';
  });
  menu.querySelectorAll('a').forEach(a => a.addEventListener('click', closeMenu));

  // Aparición al hacer scroll
  const reveals = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach(e => {
        if (e.isIntersecting) { e.target.classList.add('is-visible'); io.unobserve(e.target); }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    reveals.forEach(el => io.observe(el));
  } else {
    reveals.forEach(el => el.classList.add('is-visible'));
  }

  // Contadores animados
  const counters = document.querySelectorAll('[data-count]');
  const animate = (el) => {
    const target = +el.dataset.count, suffix = el.dataset.suffix || '';
    const start = performance.now(), dur = 1400;
    const step = (t) => {
      const p = Math.min((t - start) / dur, 1);
      el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))) + suffix;
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };
  if ('IntersectionObserver' in window) {
    const co = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) { animate(e.target); co.unobserve(e.target); } });
    }, { threshold: 0.5 });
    counters.forEach(c => co.observe(c));
  } else {
    counters.forEach(c => c.textContent = c.dataset.count + (c.dataset.suffix || ''));
  }

  // Botones de plan → preseleccionan el plan en el formulario
  const planSelect = document.getElementById('plan');
  document.querySelectorAll('[data-plan]').forEach(btn => {
    btn.addEventListener('click', () => { planSelect.value = btn.dataset.plan; });
  });

  // Formulario de contacto
  const form = document.getElementById('contactForm');
  const status = document.getElementById('formStatus');
  const setStatus = (msg, type) => { status.textContent = msg; status.className = 'form__status field--full ' + (type || ''); };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    let valid = true;
    form.querySelectorAll('[required]').forEach(input => {
      const ok = input.type === 'email'
        ? /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value.trim())
        : input.value.trim().length > 0;
      input.parentElement.classList.toggle('is-invalid', !ok);
      if (!ok) valid = false;
    });
    if (!valid) { setStatus('Revisa los campos marcados.', 'err'); return; }

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true; btn.textContent = 'Enviando…'; setStatus('');

    try {
      const res = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.ok) {
        form.reset();
        setStatus('¡Gracias! Te responderemos en menos de 24 horas.', 'ok');
      } else {
        throw new Error(data.error || 'error');
      }
    } catch (err) {
      setStatus('No se pudo enviar. Escríbenos a sales@thenovis.com', 'err');
    } finally {
      btn.disabled = false; btn.textContent = 'Enviar mensaje';
    }
  });

  form.querySelectorAll('input, textarea').forEach(i =>
    i.addEventListener('input', () => i.parentElement.classList.remove('is-invalid')));

  document.getElementById('year').textContent = new Date().getFullYear();
})();
