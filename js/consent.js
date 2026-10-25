// The Novis — aviso de cookies con consentimiento por categorías.
//  · Antes de que el visitante elija, NO se carga nada de terceros ni se instala ninguna cookie opcional.
//  · «Rechazar todas» y «Aceptar todas» tienen el mismo aspecto (como exige la AEPD). Se puede cambiar de opinión
//    en cualquier momento desde el enlace «Configurar cookies» del pie de página.
//  · Si el navegador envía la señal Global Privacy Control, se entiende como rechazo de todo lo opcional.
//  · La elección se guarda 12 meses en el navegador (localStorage, clave «novis_consent»); es almacenamiento técnico exento.
//  · Modo de consentimiento v2 de Google (básico): todo «denied» por defecto; al aceptar la analítica solo pasa a
//    «granted» analytics_storage (no usamos publicidad) y entonces se carga la etiqueta. Al retirar el consentimiento,
//    se vuelve a «denied» en la misma página y se borran las cookies de Analytics.
//
//  ► Para añadir un servicio nuevo que ponga cookies opcionales (por ejemplo, un píxel de publicidad), añade su
//    categoría a CATEGORIAS más abajo con «cargar» y «quitar», y ponlo en cookies.html y en panel → Legal.
//    Las categorías sin ningún servicio activo no se muestran; si no hay ninguna, el aviso no sale solo.
(function () {
  'use strict';

  // ID de medición de Google Analytics 4. El panel (Legal) puede cambiarlo o desactivarlo: llega en <html data-ga="…">.
  var GA_POR_DEFECTO = 'G-15CTJ93EKN';
  var attrGA = document.documentElement.getAttribute('data-ga');
  var GA_ID = attrGA !== null ? attrGA : GA_POR_DEFECTO;
  if (!/^G-[A-Z0-9]{4,20}$/.test(GA_ID)) GA_ID = '';

  var GL = document.documentElement.lang === 'gl';
  var CLAVE = 'novis_consent';
  var DURACION = 365 * 24 * 3600 * 1000;
  var T = GL ? {
    titulo: 'Cookies e privacidade',
    texto: 'Usamos cookies técnicas, necesarias para que a web funcione, e —só se as aceptas— cookies opcionais para saber como se usa a web e mellorala. Podes aceptalas, rexeitalas ou escoller por categorías.',
    mas: 'Máis información', rechazar: 'Rexeitar todas', aceptar: 'Aceptar todas', config: 'Configurar', guardar: 'Gardar a miña selección',
    nec: 'Necesarias', necD: 'Permiten iniciar sesión, pagar con seguridade e lembrar esta elección. Non se poden desactivar.',
    ana: 'Análise', anaD: 'Google Analytics: contan visitas e mostran que partes da web se usan máis. Datos agregados; sen publicidade.',
    mkt: 'Publicidade', mktD: 'Permiten mostrarche anuncios relevantes noutras webs e medir as nosas campañas.',
    gpc: 'O teu navegador envía a sinal «Global Privacy Control» e tomámola como un rexeitamento das cookies opcionais. Podes cambialo aquí.',
    region: 'Aviso de cookies'
  } : {
    titulo: 'Cookies y privacidad',
    texto: 'Usamos cookies técnicas, necesarias para que la web funcione, y —solo si las aceptas— cookies opcionales para saber cómo se usa la web y mejorarla. Puedes aceptarlas, rechazarlas o elegir por categorías.',
    mas: 'Más información', rechazar: 'Rechazar todas', aceptar: 'Aceptar todas', config: 'Configurar', guardar: 'Guardar mi selección',
    nec: 'Necesarias', necD: 'Permiten iniciar sesión, pagar con seguridad y recordar esta elección. No se pueden desactivar.',
    ana: 'Analítica', anaD: 'Google Analytics: cuentan visitas y muestran qué partes de la web se usan más. Datos agregados; sin publicidad.',
    mkt: 'Publicidad', mktD: 'Permiten mostrarte anuncios relevantes en otras webs y medir nuestras campañas.',
    gpc: 'Tu navegador envía la señal «Global Privacy Control» y la tomamos como un rechazo de las cookies opcionales. Puedes cambiarlo aquí.',
    region: 'Aviso de cookies'
  };
  var URL_COOKIES = '/cookies.html';

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
    borrarCookies(/^(_ga|_gid|_gat|_gcl)/);
  }
  function borrarCookies(patron) {
    var host = location.hostname, partes = host.split('.'), dominios = [host, '.' + host];
    if (partes.length > 2) dominios.push('.' + partes.slice(-2).join('.'));
    document.cookie.split(';').forEach(function (c) {
      var n = c.split('=')[0].trim();
      if (patron.test(n)) dominios.forEach(function (d) {
        document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=' + d;
        document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
      });
    });
  }

  /* ---------- Categorías opcionales ---------- */
  // id: clave guardada · activa: ¿hay algún servicio de esta categoría? · cargar/quitar: qué hacer al aceptar o rechazar.
  var CATEGORIAS = [
    { id: 'analitica', titulo: T.ana, desc: T.anaD, activa: !!GA_ID, cargar: cargarGA, quitar: quitarGA },
    // Ejemplo para el futuro (píxel de Meta, Google Ads…): pon activa a true y rellena cargar/quitar.
    { id: 'publicidad', titulo: T.mkt, desc: T.mktD, activa: false, cargar: function () {}, quitar: function () {} }
  ].filter(function (c) { return c.activa; });

  /* ---------- Elección guardada ---------- */
  var memoria = null;   // por si el navegador no deja usar localStorage
  function leer() {
    try {
      var v = JSON.parse(localStorage.getItem(CLAVE) || 'null');
      if (v && v.t && Date.now() - v.t < DURACION) {
        if (v.v === 1 && typeof v.a === 'number') return { v: 2, c: { analitica: v.a }, t: v.t, g: v.g };   // formato anterior
        if (v.v === 2 && v.c && typeof v.c === 'object') {
          // Si hay una categoría nueva sobre la que aún no ha elegido, se vuelve a preguntar
          for (var i = 0; i < CATEGORIAS.length; i++) if (typeof v.c[CATEGORIAS[i].id] !== 'number') return null;
          return v;
        }
      }
    } catch (e) {}
    return memoria;
  }
  function guardar(elegido, gpc) {
    var c = {};
    CATEGORIAS.forEach(function (k) { c[k.id] = elegido[k.id] ? 1 : 0; });
    var v = { v: 2, c: c, t: Date.now() };
    if (gpc) v.g = 1;
    memoria = v;
    try { localStorage.setItem(CLAVE, JSON.stringify(v)); } catch (e) {}
    aplicar(v);
    return v;
  }
  function todas(si) { var o = {}; CATEGORIAS.forEach(function (k) { o[k.id] = si; }); return o; }
  function aplicar(v) {
    CATEGORIAS.forEach(function (k) { if (v && v.c && v.c[k.id]) k.cargar(); else k.quitar(); });
  }
  var gpcActivo = navigator.globalPrivacyControl === true;
  // API mínima para otras partes de la web: window.novisConsent.acepta('analitica')
  window.novisConsent = { acepta: function (id) { var v = leer(); return !!(v && v.c && v.c[id]); }, abrir: function () { mostrar(true); } };

  /* ---------- Interfaz ---------- */
  var caja = null, opciones = null, casillas = {}, btnGuardar = null;
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
    opciones = el('div', 'cc__opts'); opciones.hidden = true; opciones.id = 'cc-opts';
    opciones.appendChild(fila(T.nec, T.necD, true, true));
    CATEGORIAS.forEach(function (k) {
      var f = fila(k.titulo, k.desc, false, false);
      casillas[k.id] = f.querySelector('input');
      opciones.appendChild(f);
    });
    var nota = el('p', 'cc__gpc', T.gpc); nota.hidden = !gpcActivo; opciones.appendChild(nota);
    var btns = el('div', 'cc__btns');
    btns.appendChild(boton(T.rechazar, '', function () { guardar(todas(false), false); cerrar(); }));
    btns.appendChild(boton(T.aceptar, '', function () { guardar(todas(true), false); cerrar(); }));
    var bc = boton(T.config, 'cc__btn--ghost', function () { alternar(); });
    bc.setAttribute('aria-expanded', 'false'); bc.setAttribute('aria-controls', 'cc-opts'); bc.id = 'cc-config';
    btns.appendChild(bc);
    btnGuardar = boton(T.guardar, 'cc__save', function () {
      var o = {}; CATEGORIAS.forEach(function (k) { o[k.id] = casillas[k.id].checked; });
      guardar(o, false); cerrar();
    });
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
    var primera = CATEGORIAS[0] && casillas[CATEGORIAS[0].id];
    if (abrir && primera) primera.focus();
  }
  function mostrar(conConfig) {
    if (!caja) construir();
    var v = leer();
    CATEGORIAS.forEach(function (k) { casillas[k.id].checked = !!(v && v.c && v.c[k.id]); });
    caja.hidden = false;
    document.documentElement.classList.add('cc-on');
    alternar(!!conConfig);
  }
  function cerrar() {
    if (!caja) return;
    caja.hidden = true;
    document.documentElement.classList.remove('cc-on');
  }

  /* ---------- Arranque ---------- */
  function iniciar() {
    var v = leer();
    if (!v && gpcActivo) v = guardar(todas(false), true);   // GPC = rechazo, sin molestar
    if (v) aplicar(v); else if (CATEGORIAS.length) mostrar(false);
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-cookies]');
      if (!b) return;
      e.preventDefault();
      mostrar(true);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();
