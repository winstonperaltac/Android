// Filas clicables (data-href)
document.addEventListener('click', function (ev) {
  const tr = ev.target.closest('tr[data-href]');
  if (tr && !ev.target.closest('a,button,input,form')) {
    window.location = tr.dataset.href;
  }
});

// Confirmación en formularios con data-confirm
document.addEventListener('submit', function (ev) {
  const msg = ev.target.dataset.confirm;
  if (msg && !confirm(msg)) ev.preventDefault();
});
