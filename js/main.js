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

  const reducir = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const tactil = window.matchMedia('(hover: none)').matches;

  // Aparición al hacer scroll: cada grupo entra con un pequeño escalonado
  if ('IntersectionObserver' in window && !reducir) {
    document.documentElement.classList.add('anim-on');
    const grupos = [
      ['#servicios .split__aside', '#servicios .services > div'],
      ['#proceso .section__head', '#proceso .step'],
      ['#planes .section__head', '#planes .plan'],
      ['#faq .split__aside', '#faq details'],
      ['#contacto .contact__intro', '#contacto .form'],
    ];
    const io = new IntersectionObserver(entries => entries.forEach(e => {
      if (!e.isIntersecting) return;
      const el = e.target;
      io.unobserve(el);
      const fin = ev => {
        if (ev.target !== el) return;
        el.removeEventListener('animationend', fin);
        el.removeAttribute('data-anim'); el.classList.remove('is-in');
      };
      el.addEventListener('animationend', fin);
      el.classList.add('is-in');
    }), { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    grupos.forEach(sels => {
      let i = 0;
      sels.forEach(sel => document.querySelectorAll(sel).forEach(el => {
        el.dataset.anim = '';
        el.style.setProperty('--i', i++ % 6);
        io.observe(el);
      }));
    });
  }

  // En móvil: se activa el paso, el plan o el servicio que está en el centro de la pantalla
  if (tactil && 'IntersectionObserver' in window) {
    const centro = new IntersectionObserver(entries => entries.forEach(e => {
      e.target.classList.toggle('is-active', e.isIntersecting);
      const grupo = e.target.parentElement;
      grupo.classList.toggle('has-active', !!grupo.querySelector('.is-active'));
    }), { rootMargin: '-42% 0px -42% 0px' });
    document.querySelectorAll('.step, .plan, .services > div').forEach(el => centro.observe(el));
  }

  // Elegir un plan: la tarjeta lo confirma, la página baja al formulario y el plan queda marcado
  const planSelect = document.getElementById('plan');
  const chip = document.getElementById('planChip');
  const planes = [...document.querySelectorAll('.plan')];
  function marcarPlan(nombre, animar) {
    planes.forEach(pl => {
      const es = !!pl.querySelector(`[data-plan="${nombre}"]`);
      pl.classList.toggle('is-chosen', es);
      pl.querySelector('.plan__elegido')?.remove();
      if (es) {
        const sello = document.createElement('span');
        sello.className = 'plan__elegido';
        sello.textContent = 'Elegido';
        pl.appendChild(sello);
      }
    });
    if (nombre && nombre !== 'Aún no lo sé') {
      chip.innerHTML = '<span>Has elegido el plan <strong></strong>. Cuéntanos tu proyecto y te enviamos el presupuesto.</span>';
      chip.querySelector('strong').textContent = nombre;
      chip.hidden = false;
      if (animar) { chip.classList.remove('is-new'); void chip.offsetWidth; chip.classList.add('is-new'); }
    } else {
      chip.hidden = true;
    }
  }
  document.querySelectorAll('[data-plan]').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      const nombre = btn.dataset.plan;
      planSelect.value = nombre;
      marcarPlan(nombre, true);
      // Deja ver el efecto en la tarjeta y luego baja al formulario
      setTimeout(() => {
        document.getElementById('contacto').scrollIntoView({ behavior: reducir ? 'auto' : 'smooth' });
        setTimeout(() => {
          const campo = planSelect.closest('.field');
          campo.classList.remove('is-flash'); void campo.offsetWidth; campo.classList.add('is-flash');
          document.getElementById('nombre').focus({ preventScroll: true });
        }, reducir ? 0 : 750);
      }, reducir ? 0 : 450);
    });
  });
  planSelect.addEventListener('change', () => marcarPlan(planSelect.value, true));

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
  // El usuario elige: se detiene el cambio automático y se queda en ese ejemplo
  function elegir(i, foco) { lista.classList.remove('is-auto'); activar(i, foco); }

  // Avanza cuando la barra de progreso de la pestaña activa termina
  lista.addEventListener('animationend', e => {
    if (e.animationName === 'progreso' && lista.classList.contains('is-auto')) activar(actual + 1);
  });

  tabs.forEach((t, i) => {
    t.addEventListener('click', () => elegir(i));
    t.addEventListener('keydown', e => {
      let n = null;
      if (e.key === 'ArrowRight') n = i + 1;
      if (e.key === 'ArrowLeft') n = i - 1;
      if (e.key === 'Home') n = 0;
      if (e.key === 'End') n = tabs.length - 1;
      if (n !== null) { e.preventDefault(); elegir(n, true); }
    });
  });

  // En el móvil: deslizar el dedo sobre el ejemplo cambia al siguiente o al anterior
  let x0 = null, y0 = null;
  seccion.addEventListener('touchstart', e => {
    if (!e.target.closest('.stage')) return;
    x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
  }, { passive: true });
  seccion.addEventListener('touchend', e => {
    if (x0 === null) return;
    const dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
    x0 = null;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) elegir(actual + (dx < 0 ? 1 : -1));
  }, { passive: true });

  // Pausa mientras el ratón está encima, mientras no se ve la sección o la pestaña del navegador está oculta
  let encima = false, fuera = false;
  const pausa = () => lista.classList.toggle('is-paused', encima || fuera || document.hidden);
  seccion.addEventListener('mouseenter', () => { encima = true; pausa(); });
  seccion.addEventListener('mouseleave', () => { encima = false; pausa(); });
  document.addEventListener('visibilitychange', pausa);
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(([e]) => { fuera = !e.isIntersecting; pausa(); }, { threshold: 0.25 })
      .observe(seccion);
  }

  if (!reducir) lista.classList.add('is-auto');
})();
