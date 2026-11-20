<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('prestamos.crear');

$clienteId = input_int('cliente_id');
$cliente = $clienteId ? q_one('SELECT * FROM clientes WHERE id = ? AND activo = 1', [$clienteId]) : null;
$errors = [];

if (is_post()) {
    csrf_check();
    $d = [
        'cliente_id'   => $clienteId,
        'tipo'         => input('tipo') === 'san' ? 'san' : 'redito',
        'frecuencia'   => input('frecuencia') === 'quincenal' ? 'quincenal' : 'semanal',
        'monto'        => input_money('monto'),
        'num_cuotas'   => input_int('num_cuotas'),
        'tasa'         => input_money('tasa'),
        'fecha_inicio' => input_date('fecha_inicio', today()),
        'notas'        => (string)input('notas', ''),
    ];
    if (!$cliente) $errors[] = 'Seleccione un cliente válido.';
    if ($d['monto'] <= 0) $errors[] = 'El monto debe ser mayor que cero.';
    if ($d['tipo'] === 'san' && ($d['num_cuotas'] < 1 || $d['num_cuotas'] > 520)) $errors[] = 'Indique un número de cuotas válido.';
    if ($d['tipo'] === 'redito' && ($d['tasa'] <= 0 || $d['tasa'] > 100)) $errors[] = 'Indique una tasa de rédito válida.';
    if (!$errors) {
        try {
            $id = prestamo_crear($d);
            flash('success', 'Préstamo creado correctamente.');
            redirect('prestamo_ver.php?id=' . $id);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$clientes = $cliente ? [] : q_all('SELECT id, cedula, nombres, apellidos, indice_credito FROM clientes WHERE activo = 1 ORDER BY apellidos, nombres');
$page_title = 'Nuevo préstamo';
require __DIR__ . '/includes/header.php';
foreach ($errors as $er) echo '<div class="alert alert-danger">' . e($er) . '</div>';
?>
<form method="post" class="card card-body" id="frm">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Cliente</label>
      <?php if ($cliente): ?>
        <input type="hidden" name="cliente_id" value="<?= $cliente['id'] ?>">
        <div class="form-control-plaintext fw-semibold"><?= e($cliente['nombres'] . ' ' . $cliente['apellidos']) ?> (<?= e($cliente['cedula']) ?>) <?= credito_badge($cliente['indice_credito']) ?></div>
      <?php else: ?>
        <select name="cliente_id" class="form-select" required>
          <option value="">Seleccione…</option>
          <?php foreach ($clientes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['apellidos'] . ', ' . $c['nombres'] . ' — ' . $c['cedula'] . ' — índice ' . ($c['indice_credito'] ?? '—')) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>
    <div class="col-md-3"><label class="form-label">Tipo de préstamo</label>
      <select name="tipo" id="tipo" class="form-select">
        <option value="redito" <?= input('tipo') === 'redito' ? 'selected' : '' ?>>Rédito (sin cuotas)</option>
        <option value="san" <?= input('tipo') === 'san' ? 'selected' : '' ?>>San (cuotas)</option>
      </select></div>
    <div class="col-md-3"><label class="form-label">Frecuencia</label>
      <select name="frecuencia" id="frecuencia" class="form-select">
        <option value="semanal">Semanal</option>
        <option value="quincenal" <?= input('frecuencia') === 'quincenal' ? 'selected' : '' ?>>Quincenal</option>
      </select></div>
    <div class="col-md-3"><label class="form-label">Monto (capital)</label><input name="monto" id="monto" type="number" step="0.01" min="1" class="form-control" required value="<?= e(input('monto', '')) ?>"></div>
    <div class="col-md-3"><label class="form-label">Fecha de inicio</label><input name="fecha_inicio" type="date" class="form-control" value="<?= e(input('fecha_inicio', today())) ?>" max="<?= today() ?>"></div>
    <div class="col-md-3 redito-only"><label class="form-label">Rédito por periodo (%)</label><input name="tasa" id="tasa" type="number" step="0.01" class="form-control" value="<?= e(input('tasa', setting('redito_tasa', 10))) ?>"></div>
    <div class="col-md-3 san-only"><label class="form-label">Número de cuotas</label><input name="num_cuotas" id="num_cuotas" type="number" min="1" class="form-control" value="<?= e(input('num_cuotas', '')) ?>"></div>
    <div class="col-12"><label class="form-label">Notas</label><textarea name="notas" class="form-control" rows="2"><?= e(input('notas', '')) ?></textarea></div>
  </div>

  <div class="alert alert-info mt-3 mb-0" id="preview">Complete los datos para ver el cálculo.</div>
  <div class="mt-3"><button class="btn btn-primary">Crear préstamo</button> <a href="prestamos.php" class="btn btn-secondary">Cancelar</a></div>
</form>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const moneda = <?= json_encode(setting('moneda', 'RD$')) ?>;
  const fmt = v => moneda + ' ' + Number(v).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  let timer;
  function update() {
    const tipo = $('tipo').value;
    document.querySelectorAll('.san-only').forEach(el => el.style.display = tipo === 'san' ? '' : 'none');
    document.querySelectorAll('.redito-only').forEach(el => el.style.display = tipo === 'redito' ? '' : 'none');
    const monto = parseFloat($('monto').value || 0);
    const frec = $('frecuencia').value === 'semanal' ? 'semana' : 'quincena';
    if (tipo === 'redito') {
      const t = parseFloat($('tasa').value || 0);
      $('preview').innerHTML = monto > 0
        ? `<strong>Rédito:</strong> ${fmt(monto * t / 100)} cada ${frec} (${t}% del capital pendiente).<br>
           El cliente puede pagar sólo el rédito, abonar a capital (pagando antes el rédito del periodo) o saldar con ${fmt(monto * (1 + t / 100))}.`
        : 'Complete los datos para ver el cálculo.';
      return;
    }
    clearTimeout(timer);
    timer = setTimeout(() => {
      const n = parseInt($('num_cuotas').value || 0);
      if (!(monto > 0 && n > 0)) { $('preview').textContent = 'Complete los datos para ver el cálculo.'; return; }
      fetch(`api_calculo.php?monto=${monto}&num_cuotas=${n}&frecuencia=${$('frecuencia').value}`)
        .then(r => r.json()).then(c => {
          if (!c.ok) { $('preview').textContent = c.error; return; }
          $('preview').innerHTML = `<div class="row">
            <div class="col-md-3"><strong>Duración:</strong> ${c.meses_exactos} meses<br><small>Se cobran ${c.meses_cobrados} mes(es)</small></div>
            <div class="col-md-3"><strong>Interés total:</strong> ${c.tasa}%<br>${fmt(c.interes_total)}</div>
            <div class="col-md-3"><strong>Total a pagar:</strong><br>${fmt(c.total)}</div>
            <div class="col-md-3"><strong>Cuota ${$('frecuencia').value}:</strong><br><span class="fs-5">${fmt(c.monto_cuota)}</span><br><small>Rédito si no completa: ${fmt(c.monto_cuota / 2)}</small></div></div>`;
        });
    }, 250);
  }
  ['tipo', 'frecuencia', 'monto', 'num_cuotas', 'tasa'].forEach(id => $(id).addEventListener('input', update));
  update();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
