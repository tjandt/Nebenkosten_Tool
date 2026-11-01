// Tabellenzeilen hinzufügen/entfernen (Aufteilungen, Sonderposten)
document.addEventListener('click', function (e) {
  const add = e.target.closest('[data-add-row]');
  if (add) {
    const tb = document.querySelector('#' + add.dataset.addRow + ' tbody');
    const last = tb.lastElementChild;
    const clone = last.cloneNode(true);
    const n = tb.children.length + Date.now() % 1000;
    clone.querySelectorAll('input,select,textarea').forEach(function (el) {
      el.name = el.name.replace(/\[\d+\]/, '[' + n + ']');
      if (el.tagName === 'SELECT') el.selectedIndex = 0;
      else if (el.type !== 'number' || !el.name.includes('jahr')) el.value = '';
    });
    tb.appendChild(clone);
  }
  const del = e.target.closest('[data-del-row]');
  if (del) {
    const tr = del.closest('tr');
    if (tr.parentElement.children.length > 1) tr.remove();
    else tr.querySelectorAll('input').forEach(function (el) { if (el.type !== 'number') el.value = ''; });
  }
});

// Alle auswählen
document.querySelectorAll('[data-check-all]').forEach(function (cb) {
  cb.addEventListener('change', function () {
    cb.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(function (c) { c.checked = cb.checked; });
  });
});

// Auslage: Fahrtkosten berechnen
(function () {
  const kat = document.getElementById('kat');
  if (!kat) return;
  const box = document.getElementById('fahrtBox');
  const km = document.getElementById('km'), hr = document.getElementById('hr'), satz = document.getElementById('satz'), betrag = document.getElementById('betrag');
  const zahl = function (s) { s = String(s || '').trim(); if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.'); return parseFloat(s) || 0; };
  function rechnen() {
    const fahrt = kat.value === 'Fahrtkosten';
    box.classList.toggle('hidden', !fahrt);
    if (fahrt && zahl(km.value) > 0) {
      betrag.value = (zahl(km.value) * (hr.checked ? 2 : 1) * zahl(satz.value)).toFixed(2).replace('.', ',');
    }
  }
  [kat, km, hr, satz].forEach(function (el) { el.addEventListener('input', rechnen); el.addEventListener('change', rechnen); });
  rechnen();
})();
