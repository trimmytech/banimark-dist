/* Runs in <head>, before first paint: a dark-mode reload must never flash white. */
try { var t = localStorage.getItem('bm-theme'); if (t) document.documentElement.setAttribute('data-theme', t); } catch (e) {}
