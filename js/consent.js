// Novis — aviso de cookies y analítica con consentimiento.
//  · Antes de que el visitante elija, NO se carga nada de Google ni se instala ninguna cookie de análisis.
//  · «Rechazar todas» y «Aceptar todas» tienen el mismo aspecto (como exige la AEPD). Se puede cambiar de opinión
//    en cualquier momento desde el enlace «Configurar cookies» del pie de página.
//  · Si el navegador envía la señal Global Privacy Control, se entiende como rechazo de la analítica.
//  · La elección se guarda 12 meses en el navegador (localStorage, clave «novis_consent»); es una cookie técnica exenta.
//  · Modo de consentimiento v2 de Google (básico): todo «denied» por defecto; al aceptar solo pasa a «granted»
//    analytics_storage (no usamos publicidad) y entonces se carga la etiqueta. Al retirar el consentimiento,
//    se vuelve a «denied» en la misma página y se borran las cookies de Analytics.
(function () {
  'use strict';

  // ► Para activar Google Analytics 4, pega aquí tu ID de medición (empieza por G-). Vacío = no se carga nada.
  var GA_ID = 'G-15CTJ93EKN';

  var GL = document.documentElement.lang === 'gl';
  var CLAVE = 'novis_consent';
  var DURACION = 365 * 24 * 3600 * 1000;
  var T = GL ? {
    titulo: 'Cookies e privacidade',
    texto: 'Usamos cookies técnicas, necesarias para que a web funcione, e —só se as aceptas— cookies de análise (Google Analytics) para saber como se usa a web e mellorala. Podes aceptalas, rexeitalas ou escoller.',
    mas: 'Máis información', rechazar: 'Rexeitar todas', aceptar: 'Aceptar todas', config: 'Configurar', guardar: 'Gardar a miña selección',
    nec: 'Necesarias', necD: 'Permiten iniciar sesión e lembrar esta elección. Non se poden desactivar.',
    ana: 'Análise', anaD: 'Google Analytics: contan visitas e mostran que partes da web se usan máis. Datos agregados; sen publicidade.',
    gpc: 'O teu navegador envía a sinal «Global Privacy Control» e tomámola como un rexeitamento da análise. Podes cambialo aquí.',
    region: 'Aviso de cookies', pechar: 'Pechar'
  } : {
    titulo: 'Cookies y privacidad',
    texto: 'Usamos cookies técnicas, necesarias para que la web funcione, y —solo si las aceptas— cookies de analítica (Google Analytics) para saber cómo se usa la web y mejorarla. Puedes aceptarlas, rechazarlas o elegir.',
    mas: 'Más información', rechazar: 'Rechazar todas', aceptar: 'Aceptar todas', config: 'Configurar', guardar: 'Guardar mi selección',
    nec: 'Necesarias', necD: 'Permiten iniciar sesión y recordar esta elección. No se pueden desactivar.',
    ana: 'Analítica', anaD: 'Google Analytics: cuentan visitas y muestran qué partes de la web se usan más. Datos agregados; sin publicidad.',
    gpc: 'Tu navegador envía la señal «Global Privacy Control» y la tomamos como un rechazo de la analítica. Puedes cambiarlo aquí.',
    region: 'Aviso de cookies', pechar: 'Cerrar'
  };
  var URL_COOKIES = '/cookies.html';

  /* ---------- Elección guardada ---------- */
  function leer() {
    try {
      var v = JSON.parse(localStorage.getItem(CLAVE) || 'null');
      if (v && v.v === 1 && typeof v.a === 'number' && v.t && Date.now() - v.t < DURACION) return v;
    } catch (e) {}
    return memoria;
  }
  var memoria = null;   // por si el navegador no deja usar localStorage
  function guardar(analitica, gpc) {
    var v = { v: 1, a: analitica ? 1 : 0, t: Date.now() };
    if (gpc) v.g = 1;
    memoria = v;
    try { localStorage.setItem(CLAVE, JSON.stringify(v)); } catch (e) {}
    aplicar(v);
    return v;
  }
  var gpcActivo = navigator.globalPrivacyControl === true;

  /* ---------- Google Analytics (solo con consentimiento) ---------- */
  var DENEGADO = { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied' };
  var gtag = function () {};
  if (GA_ID) {
    // Estado por defecto antes de cualquier otra orden de Google, como pide el modo de consentimiento.
    window.dataLayer = window.dataLayer || [];
    gtag = window.gtag = function () { window.dataLayer.push(arguments); };
    gtag('consent', 'default', DENEGADO);
    gtag('set', 'ads_data_redaction', true);
  }
  var gaCargado = false;
  function cargarGA() {
    if (!GA_ID) return;
    window['ga-disable-' + GA_ID] = false;
    gtag('consent', 'update', { analytics_storage: 'granted' });
    if (gaCargado) return;
    gaCargado = true;
    gtag('js', new Date());
    gtag('config', GA_ID, { allow_google_signals: false, allow_ad_personalization_signals: false });
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA_ID);
    document.head.appendChild(s);
  }
  function quitarGA() {
    if (GA_ID) {
      if (gaCargado) gtag('consent', 'update', DENEGADO);
      window['ga-disable-' + GA_ID] = true;
    }
    var host = location.hostname, partes = host.split('.'), dominios = [host, '.' + host];
    if (partes.length > 2) dominios.push('.' + partes.slice(-2).join('.'));
    document.cookie.split(';').forEach(function (c) {
      var n = c.split('=')[0].trim();
      if (/^(_ga|_gid|_gat|_gcl)/.test(n)) dominios.forEach(function (d) {
        document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=' + d;
        document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
      });
    });
  }
  function aplicar(v) { if (v && v.a) cargarGA(); else quitarGA(); }

  /* ---------- Interfaz ---------- */
  var caja = null, opciones = null, chkAna = null, btnGuardar = null, abierto = false;
  function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt) e.textContent = txt; return e; }
  function boton(txt, cls, fn) { var b = el('button', 'cc__btn' + (cls ? ' ' + cls : ''), txt); b.type = 'button'; b.addEventListener('click', fn); return b; }

  function construir() {
    caja = el('div', 'cc');
    caja.setAttribute('role', 'region');
    caja.setAttribute('aria-label', T.region);
    var in_ = el('div', 'cc__box');
    var h = el('h2', 'cc__t', T.titulo); h.id = 'cc-t'; h.tabIndex = -1;
    var p = el('p', 'cc__p', T.texto + ' ');
    var a = el('a', null, T.mas); a.href = URL_COOKIES; p.appendChild(a);
    opciones = el('div', 'cc__opts'); opciones.hidden = true;
    opciones.appendChild(fila(T.nec, T.necD, true, true));
    var f = fila(T.ana, T.anaD, false, false); chkAna = f.querySelector('input'); opciones.appendChild(f);
    var nota = el('p', 'cc__gpc', T.gpc); nota.hidden = !gpcActivo; opciones.appendChild(nota);
    var btns = el('div', 'cc__btns');
    btns.appendChild(boton(T.rechazar, '', function () { cerrar(guardar(false, false)); }));
    btns.appendChild(boton(T.aceptar, '', function () { cerrar(guardar(true, false)); }));
    var bc = boton(T.config, 'cc__btn--ghost', function () { alternar(); });
    bc.setAttribute('aria-expanded', 'false'); bc.setAttribute('aria-controls', 'cc-opts'); bc.id = 'cc-config';
    opciones.id = 'cc-opts';
    btns.appendChild(bc);
    btnGuardar = boton(T.guardar, 'cc__save', function () { cerrar(guardar(chkAna.checked, false)); });
    btnGuardar.hidden = true;
    in_.appendChild(h); in_.appendChild(p); in_.appendChild(opciones); in_.appendChild(btns); in_.appendChild(btnGuardar);
    caja.appendChild(in_);
    caja.addEventListener('keydown', function (e) { if (e.key === 'Escape' && leer()) cerrar(); });
    document.body.appendChild(caja);
  }
  function fila(titulo, desc, marcado, fijo) {
    var l = el('label', 'cc__row');
    var i = el('input'); i.type = 'checkbox'; i.checked = marcado; if (fijo) { i.disabled = true; }
    var t = el('span', 'cc__rt'); t.appendChild(el('strong', null, titulo)); t.appendChild(el('small', null, desc));
    l.appendChild(i); l.appendChild(t);
    return l;
  }
  function alternar(forzar) {
    var abrir = typeof forzar === 'boolean' ? forzar : opciones.hidden;
    opciones.hidden = !abrir; btnGuardar.hidden = !abrir;
    var bc = document.getElementById('cc-config'); if (bc) bc.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    if (abrir) chkAna.focus();
  }
  function mostrar(conConfig) {
    if (!caja) construir();
    var v = leer();
    chkAna.checked = !!(v && v.a);
    caja.hidden = false; abierto = true;
    document.documentElement.classList.add('cc-on');
    if (conConfig) { alternar(true); } else { alternar(false); }
  }
  function cerrar() {
    if (!caja) return;
    caja.hidden = true; abierto = false;
    document.documentElement.classList.remove('cc-on');
  }

  /* ---------- Arranque ---------- */
  function iniciar() {
    var v = leer();
    if (!v && gpcActivo) v = guardar(false, true);   // GPC = rechazo, sin molestar
    if (v) aplicar(v); else mostrar(false);
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-cookies]');
      if (!b) return;
      e.preventDefault();
      mostrar(true);
      var h = document.getElementById('cc-t'); if (h && !chkAna) h.focus();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();
