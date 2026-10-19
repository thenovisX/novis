// ===== Novis — asistente virtual =====
(function () {
  const historial = [];
  const SALUDO = '¡Hola! Soy el asistente virtual de Novis, una inteligencia artificial. Puedo resolver dudas sobre nuestros servicios y precios. ¿En qué te ayudo?';
  const SUGERENCIAS = ['¿Cuánto cuesta una web?', '¿Cuánto tardáis?', '¿Hacéis tiendas online?'];

  const raiz = document.createElement('div');
  raiz.className = 'chat';
  raiz.innerHTML = `
    <div class="chat__panel" id="chatPanel" role="dialog" aria-label="Asistente de Novis" aria-hidden="true">
      <header class="chat__head">
        <span class="chat__avatar"></span>
        <div><strong>Asistente Novis</strong><small>Asistente con inteligencia artificial</small></div>
        <button class="chat__close" type="button" aria-label="Cerrar chat">×</button>
      </header>
      <div class="chat__body" id="chatBody" aria-live="polite"></div>
      <div class="chat__chips" id="chatChips"></div>
      <p class="chat__note">Respuestas generadas por IA; pueden contener errores. No compartas datos personales. <a href="privacidad.html#chat">Privacidad</a></p>
      <form class="chat__form" id="chatForm" autocomplete="off">
        <input type="text" id="chatInput" placeholder="Escribe tu pregunta…" maxlength="800" aria-label="Tu mensaje">
        <button type="submit" aria-label="Enviar"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
      </form>
    </div>
    <button class="chat__fab" id="chatFab" type="button" aria-label="Abrir chat" aria-expanded="false" aria-controls="chatPanel">
      <svg class="i-open" viewBox="0 0 24 24"><path d="M4 5h16v11H8l-4 4z"/></svg>
      <svg class="i-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>`;
  document.body.appendChild(raiz);

  const panel = raiz.querySelector('#chatPanel');
  const fab = raiz.querySelector('#chatFab');
  const body = raiz.querySelector('#chatBody');
  const chips = raiz.querySelector('#chatChips');
  const form = raiz.querySelector('#chatForm');
  const input = raiz.querySelector('#chatInput');
  let ocupado = false;

  const escapar = (s) => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const formatear = (s) => escapar(s)
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\b([\w.+-]+@[\w-]+\.[\w.]+)\b/g, '<a href="mailto:$1">$1</a>')
    .replace(/\n/g, '<br>');

  function burbuja(texto, quien) {
    const el = document.createElement('div');
    el.className = 'chat__msg chat__msg--' + quien;
    el.innerHTML = formatear(texto);
    body.appendChild(el);
    body.scrollTop = body.scrollHeight;
    return el;
  }

  function abrir(estado) {
    const open = estado ?? !raiz.classList.contains('is-open');
    raiz.classList.toggle('is-open', open);
    panel.setAttribute('aria-hidden', String(!open));
    fab.setAttribute('aria-expanded', String(open));
    if (open) {
      if (!body.children.length) {
        burbuja(SALUDO, 'bot');
        SUGERENCIAS.forEach(t => {
          const b = document.createElement('button');
          b.type = 'button'; b.textContent = t;
          b.addEventListener('click', () => enviar(t));
          chips.appendChild(b);
        });
      }
      setTimeout(() => input.focus(), 250);
    }
  }

  async function enviar(texto) {
    texto = texto.trim();
    if (!texto || ocupado) return;
    ocupado = true;
    chips.innerHTML = '';
    burbuja(texto, 'user');
    historial.push({ role: 'user', text: texto });
    input.value = '';

    const escribiendo = burbuja('', 'bot');
    escribiendo.classList.add('chat__msg--typing');
    escribiendo.innerHTML = '<i></i><i></i><i></i>';

    let respuesta;
    try {
      const res = await fetch('chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: historial })
      });
      const data = await res.json();
      respuesta = data.reply || data.error;
    } catch (e) { /* sin conexión */ }
    respuesta = respuesta || 'Ahora mismo no puedo responder. Escríbenos a hola@thenovis.com.';

    escribiendo.classList.remove('chat__msg--typing');
    escribiendo.innerHTML = formatear(respuesta);
    body.scrollTop = body.scrollHeight;
    historial.push({ role: 'model', text: respuesta });
    ocupado = false;
    input.focus();
  }

  fab.addEventListener('click', () => abrir());
  raiz.querySelector('.chat__close').addEventListener('click', () => abrir(false));
  form.addEventListener('submit', (e) => { e.preventDefault(); enviar(input.value); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && raiz.classList.contains('is-open')) abrir(false); });
})();
