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
      const ok = input.type === 'checkbox' ? input.checked
        : input.type === 'email' ? /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value.trim())
        : input.value.trim().length > 0;
      input.parentElement.classList.toggle('is-invalid', !ok);
      input.setAttribute('aria-invalid', String(!ok));
      if (!ok) valid = false;
    });
    if (!valid) {
      const acepto = form.querySelector('[name="acepto"]');
      setStatus(acepto && !acepto.checked ? 'Debes aceptar la política de privacidad.' : 'Revisa los campos marcados.', 'err');
      const primero = form.querySelector('[aria-invalid="true"]');
      if (primero) primero.focus();
      return;
    }

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
      setStatus('No se pudo enviar. Escríbenos a hola@thenovis.com', 'err');
    } finally {
      btn.disabled = false; btn.textContent = 'Enviar mensaje';
    }
  });

  form.querySelectorAll('input, textarea').forEach(i =>
    i.addEventListener(i.type === 'checkbox' ? 'change' : 'input', () => {
      i.parentElement.classList.remove('is-invalid'); i.removeAttribute('aria-invalid');
    }));

  document.getElementById('year').textContent = new Date().getFullYear();
})();

// ===== Ejemplos: pestañas con cambio automático =====
(function () {
  const lista = document.querySelector('.work__tabs');
  if (!lista) return;
  const tabs = [...lista.querySelectorAll('[role="tab"]')];
  const seccion = document.getElementById('trabajos');
  const boton = document.getElementById('workPlay');
  const reducir = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let actual = 0;

  function activar(i, foco) {
    actual = (i + tabs.length) % tabs.length;
    tabs.forEach((t, n) => {
      const on = n === actual;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
    });
    if (foco) tabs[actual].focus();
  }

  // Cambio automático: avanza cuando la barra de progreso de la pestaña activa termina
  function auto(on) {
    lista.classList.toggle('is-auto', on);
    boton.classList.toggle('is-stopped', !on);
    boton.setAttribute('aria-label', on ? 'Pausar el cambio automático' : 'Reanudar el cambio automático');
  }
  lista.addEventListener('animationend', e => {
    if (e.animationName === 'progreso' && lista.classList.contains('is-auto')) activar(actual + 1);
  });

  // Se detiene en cuanto el usuario elige un ejemplo
  tabs.forEach((t, i) => {
    t.addEventListener('click', () => { auto(false); activar(i); });
    t.addEventListener('keydown', e => {
      let n = null;
      if (e.key === 'ArrowRight') n = i + 1;
      if (e.key === 'ArrowLeft') n = i - 1;
      if (e.key === 'Home') n = 0;
      if (e.key === 'End') n = tabs.length - 1;
      if (n !== null) { e.preventDefault(); auto(false); activar(n, true); }
    });
  });
  boton.addEventListener('click', () => {
    const reanudar = !lista.classList.contains('is-auto');
    if (reanudar) activar(actual + 1);
    auto(reanudar);
  });

  // Pausa mientras el ratón está encima, mientras no se ve la sección o la pestaña del navegador está oculta
  let encima = false, fuera = false;
  const pausa = () => lista.classList.toggle('is-paused', encima || fuera || document.hidden);
  seccion.addEventListener('mouseenter', () => { encima = true; pausa(); });
  seccion.addEventListener('mouseleave', () => { encima = false; pausa(); });
  document.addEventListener('visibilitychange', pausa);
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(([e]) => { fuera = !e.isIntersecting; pausa(); }, { threshold: 0.25 })
      .observe(seccion.querySelector('.work__panel').parentElement);
  }

  boton.hidden = false;
  auto(!reducir);
})();
