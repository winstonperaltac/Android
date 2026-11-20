<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('ganancias.ver');

// Movimientos manuales de capital (sólo administrador): aporte, retiro, reinversión de ganancias
if (is_post()) {
    csrf_check();
    require_perm('configuracion');
    $tipo = input('tipo');
    $monto = input_money('monto');
    $fecha = input_date('fecha', today());
    $desc = (string)input('descripcion', '');
    if (!in_array($tipo, ['aporte', 'retiro', 'reinversion_manual'], true) || $monto <= 0) {
        flash('danger', 'Datos inválidos.');
    } else {
        $signo = $tipo === 'retiro' ? -1 : 1;
        $mid = movimiento_registrar($tipo, 'manual', $signo * $monto, $desc ?: TIPOS_MOVIMIENTO[$tipo], $fecha);
        audit('crear', 'movimientos', (string)$mid, TIPOS_MOVIMIENTO[$tipo] . ' ' . money($monto) . " ($desc)", null, ['tipo' => $tipo, 'monto' => $monto, 'fecha' => $fecha]);
        flash('success', 'Movimiento registrado.');
    }
    redirect('reinversion.php');
}

$hist = finanzas_totales();
// Crecimiento mensual del capital: reinversión + aportes acumulados
$meses = q_all("SELECT DATE_FORMAT(fecha, '%Y-%m') mes,
                       SUM(CASE WHEN tipo = 'reinversion' THEN monto ELSE 0 END) reinv,
                       SUM(CASE WHEN tipo = 'reinversion_manual' THEN monto ELSE 0 END) reinv_manual,
                       SUM(CASE WHEN tipo = 'aporte' THEN monto ELSE 0 END) aporte,
                       SUM(CASE WHEN tipo = 'retiro' THEN monto ELSE 0 END) retiro,
                       SUM(CASE WHEN tipo = 'ganancia' THEN monto ELSE 0 END) ganancia,
                       SUM(CASE WHEN tipo IN ('gasto','sueldo') THEN monto ELSE 0 END) egresos
                  FROM movimientos GROUP BY mes ORDER BY mes");
$acum = 0.0;
foreach ($meses as &$m) {

    $acum += (float)$m['reinv'] + (float)$m['reinv_manual'] + (float)$m['aporte'];
    $m['acum'] = $acum;
}
unset($m);
$maxAcum = max(1, $acum);
$cartera = (float)q_val("SELECT COALESCE(SUM(capital_pendiente),0) FROM prestamos WHERE estado = 'activo'");
$recientes = q_all("SELECT m.*, p.codigo FROM movimientos m LEFT JOIN prestamos p ON p.id = m.prestamo_id
                     WHERE m.tipo IN ('reinversion','reinversion_manual','aporte','retiro') ORDER BY m.fecha DESC, m.id DESC LIMIT 30");

$page_title = 'Reinversión y crecimiento del capital';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Aportes de capital</div><div class="value"><?= money($hist['aporte']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Interés reinvertido (50%)</div><div class="value text-primary"><?= money($hist['reinversion']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Ganancias reinvertidas</div><div class="value text-primary"><?= money($hist['reinversion_manual']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Capital del negocio</div><div class="value"><?= money($hist['aporte'] + $hist['reinversion'] + $hist['reinversion_manual']) ?></div><small class="text-muted">Aportes + reinversión</small></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Capital prestado en la calle</div><div class="value"><?= money($cartera) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Capital en caja</div><div class="value"><?= money($hist['capital_caja']) ?></div><small class="text-muted">Aportes + reinversión + recuperado − prestado</small></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Ganancia neta disponible</div><div class="value text-success"><?= money($hist['ganancia_neta']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Retiros del dueño</div><div class="value text-danger"><?= money(-$hist['retiro']) ?></div></div></div>
</div>

<?php if (can('configuracion')): ?>
<form method="post" class="card card-body mb-3 no-print">
  <?= csrf_field() ?>
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">Movimiento</label><select name="tipo" class="form-select form-select-sm">
      <option value="reinversion_manual">Reinvertir ganancias al capital</option><option value="aporte">Aporte de capital</option><option value="retiro">Retiro de ganancias</option></select></div>
    <div class="col-md-2"><label class="form-label small">Monto</label><input type="number" step="0.01" min="0.01" name="monto" class="form-control form-control-sm" required></div>
    <div class="col-md-2"><label class="form-label small">Fecha</label><input type="date" name="fecha" value="<?= today() ?>" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small">Descripción</label><input name="descripcion" class="form-control form-control-sm"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Registrar</button></div>
  </div>
</form>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-header bg-white fw-semibold">Crecimiento mensual del capital</div>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Mes</th><th class="text-end">Ganancia</th><th class="text-end">Gastos/sueldos</th><th class="text-end">Interés reinvertido</th><th class="text-end">Ganancia reinvertida</th><th class="text-end">Aportes</th><th class="text-end">Capital acumulado</th><th style="width:22%"></th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($meses) as $m): ?>
      <tr><td><?= e($m['mes']) ?></td><td class="text-end"><?= money($m['ganancia']) ?></td><td class="text-end text-danger"><?= money(-$m['egresos']) ?></td>
      <td class="text-end"><?= money($m['reinv']) ?></td><td class="text-end"><?= money($m['reinv_manual']) ?></td><td class="text-end"><?= money($m['aporte']) ?></td>
      <td class="text-end fw-semibold"><?= money($m['acum']) ?></td>
      <td><div class="progress" style="height:10px"><div class="progress-bar" style="width:<?= round($m['acum'] / $maxAcum * 100) ?>%"></div></div></td></tr>
    <?php endforeach; if (!$meses): ?><tr><td colspan="8" class="text-center text-muted py-3">Sin datos aún.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<div class="card">
  <div class="card-header bg-white fw-semibold">Últimos movimientos de capital</div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Fecha</th><th>Tipo</th><th>Préstamo</th><th>Descripción</th><th class="text-end">Monto</th></tr></thead>
    <tbody>
    <?php foreach ($recientes as $m): ?>
      <tr class="clickable" <?= $m['prestamo_id'] ? 'data-href="prestamo_ver.php?id=' . $m['prestamo_id'] . '"' : '' ?>>
        <td><?= fdate($m['fecha']) ?></td><td><?= e(TIPOS_MOVIMIENTO[$m['tipo']] ?? $m['tipo']) ?></td><td><?= e($m['codigo'] ?? '—') ?></td><td><?= e($m['descripcion']) ?></td>
        <td class="text-end <?= $m['monto'] < 0 ? 'text-danger' : 'text-primary' ?>"><?= money($m['monto']) ?></td></tr>
    <?php endforeach; if (!$recientes): ?><tr><td colspan="5" class="text-center text-muted py-3">Sin movimientos.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
