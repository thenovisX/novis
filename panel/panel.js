// Novis — panel
document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(b.dataset.copy); b.textContent = 'Copiado ✓'; setTimeout(() => b.textContent = 'Copiar email', 1500); } catch (e) {}
}));
document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => {
  if (!confirm(f.dataset.confirm)) e.preventDefault();
}));
if (document.body.dataset.autorefresh) {
  // Actualiza la lista cada minuto si la app está abierta
  setInterval(() => { if (document.visibilityState === 'visible' && document.activeElement.tagName !== 'INPUT') location.reload(); }, 60000);
}
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
