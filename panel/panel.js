// The Novis — panel
document.querySelectorAll('[data-copy], [data-copy-from]').forEach(b => b.addEventListener('click', async () => {
  const texto = b.dataset.copy ?? document.getElementById(b.dataset.copyFrom).value;
  const original = b.textContent;
  try { await navigator.clipboard.writeText(texto); b.textContent = b.dataset.copied || 'Copiado ✓'; setTimeout(() => b.textContent = original, 1500); } catch (e) {}
}));
document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => {
  if (!confirm(f.dataset.confirm)) e.preventDefault();
}));
if (document.body.dataset.autorefresh) {
  // Actualiza la lista cada minuto si la app está abierta
  setInterval(() => { if (document.visibilityState === 'visible' && document.activeElement.tagName !== 'INPUT' && !document.body.classList.contains('selecting')) location.reload(); }, 60000);
}
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

// Modo selección para eliminar varias solicitudes
const btnSel = document.getElementById('btnSeleccionar');
if (btnSel) {
  const checks = [...document.querySelectorAll('.pick input')];
  const borrar = document.getElementById('btnBorrarSel');
  const actualizar = () => {
    const n = checks.filter(c => c.checked).length;
    borrar.disabled = n === 0;
    borrar.textContent = n ? `Eliminar (${n})` : 'Eliminar';
  };
  const salir = () => { document.body.classList.remove('selecting'); checks.forEach(c => c.checked = false); actualizar(); btnSel.textContent = 'Seleccionar'; };
  btnSel.addEventListener('click', () => {
    if (document.body.classList.toggle('selecting')) btnSel.textContent = 'Listo'; else salir();
  });
  document.getElementById('btnCancelar').addEventListener('click', salir);
  document.getElementById('btnTodas').addEventListener('click', () => {
    const todas = checks.every(c => c.checked);
    checks.forEach(c => c.checked = !todas); actualizar();
  });
  checks.forEach(c => c.addEventListener('change', actualizar));
  // En modo selección, tocar la tarjeta marca/desmarca
  document.querySelectorAll('.row').forEach(r => r.addEventListener('click', e => {
    if (!document.body.classList.contains('selecting') || e.target.closest('.pick')) return;
    const c = r.querySelector('.pick input'); c.checked = !c.checked; actualizar();
  }));
}

// Planes: precio con IVA mientras se escribe
const precio = document.getElementById('precio');
const conIva = document.getElementById('conIva');
if (precio && conIva) {
  const calc = () => {
    let t = precio.value.replace(/[\s€]/g, '');
    if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
    const n = parseFloat(t);
    conIva.textContent = t === '' ? 'Sin precio: en la web saldrá «Consultar precio».'
      : isNaN(n) ? '' : 'Con IVA (21 %): ' + (n * 1.21).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  };
  precio.addEventListener('input', calc);
  calc();
}
