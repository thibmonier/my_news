/* Briefly AI — bascule de thème clair/sombre.
 * Écrit data-theme sur <html> + persiste dans localStorage.
 * Sans JS : le thème suit prefers-color-scheme (cf tokens.css). Dégradation gracieuse.
 */
(function () {
  'use strict';
  var root = document.documentElement;

  function current() {
    var explicit = root.getAttribute('data-theme');
    if (explicit) { return explicit; }
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function apply(theme) {
    root.setAttribute('data-theme', theme);
    try { localStorage.setItem('theme', theme); } catch (e) { /* stockage indisponible */ }
  }

  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-theme-toggle]');
    if (!btn) { return; }
    apply(current() === 'dark' ? 'light' : 'dark');
  });
})();
