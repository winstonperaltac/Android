<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();

// Recalcula índices una vez al día (las cuotas vencidas afectan el índice con el paso del tiempo)
if (setting('credito_ultimo_recalculo') !== today()) {
    credito_recalcular_todos();
}

$activos = q_all("SELECT p.*, c.nombres, c.apellidos FROM prestamos p JOIN clientes c ON c.id = p.cliente_id WHERE p.estado = 'activo'");
$cartera = 0.0; $conAtraso = 0; $montoAtrasado = 0.0;
$venceHoy = [];
foreach ($activos as $p) {
    $r = prestamo_resumen($p);
    $cartera += $r['balance'];
    if ($r['atrasadas'] > 0) { $conAtraso++; $montoAtrasado += $r['monto_atrasado']; }
    if ($r['proximo'] === today()) $venceHoy[] = $p + ['res' => $r];
}
[$mIni, $mFin] = periodo_rango('mes');
$fin = finanzas_totales($mIni, $mFin);
$totalClientes = (int)q_val('SELECT COUNT(*) FROM clientes WHERE activo = 1');
$cobradoHoy = (float)q_val('SELECT COALESCE(SUM(monto),0) FROM pagos WHERE fecha = ? AND anulado = 0', [today()]);

$page_title = 'Panel principal';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3"><div class="card stat-card card-body"><div class="label">Préstamos activos</div><div class="value"><?= count($activos) ?></div></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card card-body"><div class="label">Cartera por cobrar</div><div class="value"><?= money($cartera) ?></div></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card card-body"><div class="label">Préstamos con atraso</div><div class="value text-danger"><?= $conAtraso ?></div><small class="text-muted"><?= money($montoAtrasado) ?> vencido</small></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card card-body"><div class="label">Cobrado hoy</div><div class="value text-success"><?= money($cobradoHoy) ?></div></div></div>
  <?php if (can('ganancias.ver')): ?>
  <div class="col-6 col-xl-3"><div class="card stat-card card-body"><div class="label">Ganancias del mes</div><div class="value"><?= money($fin['ganancia']) ?></div></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card card-body"><div class="label">Gastos + sueldos del mes</div><div class="value text-danger"><?= money(-($fin['gasto'] + $fin['sueldo'])) ?></div></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card card-body"><div class="label">Ganancia neta del mes</div><div class="value"><?= money($fin['ganancia_neta']) ?></div></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card card-body"><div class="label">Reinvertido este mes</div><div class="value text-primary"><?= money($fin['reinversion'] + $fin['reinversion_manual']) ?></div></div></div>
  <?php endif; ?>
  <div class="col-6 col-xl-3"><div class="card stat-card card-body"><div class="label">Clientes</div><div class="value"><?= $totalClientes ?></div></div></div>
</div>

<div class="card">
  <div class="card-header bg-white fw-semibold">Vencen hoy (<?= fdate(today()) ?>)</div>
  <div class="table-responsive">
  <table class="table table-hover mb-0">
    <thead><tr><th>Préstamo</th><th>Cliente</th><th>Tipo</th><th class="text-end">Monto a cobrar</th></tr></thead>
    <tbody>
    <?php foreach ($venceHoy as $p): ?>
      <tr class="clickable" data-href="prestamo_ver.php?id=<?= $p['id'] ?>">
        <td><?= e($p['codigo']) ?></td><td><?= e($p['nombres'] . ' ' . $p['apellidos']) ?></td>
        <td><?= $p['tipo'] === 'san' ? 'San' : 'Rédito' ?> (<?= e($p['frecuencia']) ?>)</td>
        <td class="text-end"><?= money($p['res']['proximo_monto']) ?></td>
      </tr>
    <?php endforeach; if (!$venceHoy): ?>
      <tr><td colspan="4" class="text-muted text-center py-3">No hay vencimientos para hoy.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
