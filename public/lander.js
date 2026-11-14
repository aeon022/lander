/* Lander: blendet Bereiche aus, die per Text erkannt werden müssen (Einstellungen, KI-Übersetzen).
   Wird von sidebar.js auf jeder Seite geladen; reagiert auch auf nachträglich gerenderte Inhalte. */
(function () {
  'use strict';
  var GROUPS = ['AI Assistant', 'GitHub', 'Public API', 'Preview URLs', 'Media', 'Email notifications', 'Templates', 'FTP / FTPS Deploy', 'GitHub Sync'];
  var ROWS = ['Webhook URL'];
  function apply() {
    if (/^Orbiter( Admin)?/.test(document.title)) document.title = document.title.replace(/^Orbiter( Admin)?/, 'Lander');
    document.querySelectorAll('a.logo').forEach(function (a) { var last = a.lastChild; if (last && last.nodeType === 3 && last.textContent.trim() === 'Orbiter') last.textContent = 'Lander'; });
    document.querySelectorAll('.group-header').forEach(function (h) {
      if (GROUPS.indexOf(h.textContent.trim()) > -1 && h.parentElement) h.parentElement.style.display = 'none';
    });
    document.querySelectorAll('.setting-label').forEach(function (l) {
      if (ROWS.indexOf(l.textContent.trim()) > -1) { var row = l.closest('.setting-row'); if (row) row.style.display = 'none'; }
    });
    document.querySelectorAll('button').forEach(function (b) {
      if (/^✦ (Translate|Suggest|AI)/.test(b.textContent.trim())) b.style.display = 'none';
      if (b.hasAttribute('data-open-reply')) b.style.display = 'none';   // Inbox: Antworten aus dem Mailprogramm (Reply-To der Anfrage-Mail)
    });
    document.querySelectorAll('.snippet-card').forEach(function (d) { d.style.display = 'none'; });   // Inbox: Orbiter-Anleitung passt nicht
  }
  var t; new MutationObserver(function () { clearTimeout(t); t = setTimeout(apply, 30); }).observe(document.documentElement, { childList: true, subtree: true });
  document.addEventListener('DOMContentLoaded', apply);
})();
