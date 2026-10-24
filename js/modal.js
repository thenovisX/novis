// The Novis — ventanas emergentes (<dialog>): se abren con [data-modal="id"] y se cierran con la X, Escape,
// pulsando fuera o al elegir un plan dentro de ellas.
(function () {
  'use strict';
  var root = document.documentElement;
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var abre = t.closest('[data-modal]');
    if (abre) {
      var d = document.getElementById(abre.getAttribute('data-modal'));
      if (d && typeof d.showModal === 'function') { e.preventDefault(); d.showModal(); d.querySelector('.modal__body').scrollTop = 0; root.classList.add('modal-open'); }
      return;
    }
    var abierto = t.closest('dialog.modal');
    if (t.matches && t.matches('dialog.modal[open]')) { t.close(); return; }   // clic en el fondo
    if (abierto && (t.closest('[data-modal-close]') || t.closest('[data-plan]'))) abierto.close();
  });
  document.querySelectorAll('dialog.modal').forEach(function (d) {
    d.addEventListener('close', function () { if (!document.querySelector('dialog.modal[open]')) root.classList.remove('modal-open'); });
  });
})();
