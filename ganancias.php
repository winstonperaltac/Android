<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('ganancias.ver');

[$defIni, $defFin] = periodo_rango('mes');
$desde = input_date('desde', $defIni);
$hasta = input_date('hasta', $defFin);
$origen = in_array(input('origen'), ['redito', 'interes'], true) ? input('origen') : '';

$where = "m.tipo = 'ganancia' AND m.fecha BETWEEN ? AND ?";
$params = [$desde, $hasta];
if ($origen) { $where .= ' AND m.origen = ?'; $params[] = $origen; }

$total = (int)q_val("SELECT COUNT(*) FROM movimientos m WHERE $where", $params);
$pg = paginate($total, 50);
$rows = q_all("SELECT m.*, p.codigo, p.tipo ptipo, c.nombres, c.apellidos, pa.recibo
                 FROM movimientos m
            LEFT JOIN prestamos p ON p.id = m.prestamo_id
            LEFT JOIN clientes c ON c.id = p.cliente_id
            LEFT JOIN pagos pa ON pa.id = m.pago_id
                WHERE $where ORDER BY m.fecha DESC, m.id DESC LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);
$t = finanzas_totales($desde, $hasta);
$porOrigen = q_all("SELECT origen, SUM(monto) s FROM movimientos WHERE tipo = 'ganancia' AND fecha BETWEEN ? AND ? GROUP BY origen", [$desde, $hasta]);
$po = array_column($porOrigen, 's', 'origen');

$page_title = 'Ganancias';
require __DIR__ . '/includes/header.php';
?>
<form class="row g-2 mb-3 align-items-end no-print">
  <div class="col-md-3"><label class="form-label small">Desde</label><input type="date" name="desde" value="<?= $desde ?>" class="form-control"></div>
  <div class="col-md-3"><label class="form-label small">Hasta</label><input type="date" name="hasta" value="<?= $hasta ?>" class="form-control"></div>
  <div class="col-md-3"><label class="form-label small">Origen</label><select name="origen" class="form-select"><option value="">Todos</option><option value="redito" <?= $origen === 'redito' ? 'selected' : '' ?>>Rédito</option><option value="interes" <?= $origen === 'interes' ? 'selected' : '' ?>>Interés de cuotas (50%)</option></select></div>
  <div class="col-md-3"><button class="btn btn-primary w-100">Filtrar</button></div>
</form>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Ganancia bruta</div><div class="value text-success"><?= money($t['ganancia']) ?></div><small class="text-muted">Rédito <?= money($po['redito'] ?? 0) ?> · Interés <?= money($po['interes'] ?? 0) ?></small></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Gastos operativos</div><div class="value text-danger"><?= money(-$t['gasto']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Sueldos pagados</div><div class="value text-danger"><?= money(-$t['sueldo']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Ganancia neta</div><div class="value"><?= money($t['ganancia_neta']) ?></div><small class="text-muted">Después de gastos, sueldos, retiros y reinversión</small></div></div>
</div>
<div class="card"><div class="table-responsive">
<table class="table table-sm table-hover mb-0">
  <thead><tr><th>Fecha</th><th>Origen</th><th>Préstamo</th><th>Cliente</th><th>Recibo</th><th>Descripción</th><th class="text-end">Monto</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $m): ?>
    <tr class="clickable" <?= $m['prestamo_id'] ? 'data-href="prestamo_ver.php?id=' . $m['prestamo_id'] . '"' : '' ?>>
      <td><?= fdate($m['fecha']) ?></td>
      <td><?= $m['origen'] === 'redito' ? '<span class="badge bg-warning text-dark">Rédito</span>' : '<span class="badge bg-info text-dark">Interés</span>' ?></td>
      <td><?= $m['prestamo_id'] ? '<a href="prestamo_ver.php?id=' . $m['prestamo_id'] . '">' . e($m['codigo']) . '</a>' : '—' ?></td>
      <td><?= e(trim(($m['nombres'] ?? '') . ' ' . ($m['apellidos'] ?? ''))) ?></td>
      <td><?= $m['pago_id'] ? '<a href="recibo.php?id=' . $m['pago_id'] . '">' . e($m['recibo']) . '</a>' : '' ?></td>
      <td><?= e($m['descripcion']) ?></td>
      <td class="text-end text-success"><?= money($m['monto']) ?></td>
    </tr>
  <?php endforeach; if (!$rows): ?>
    <tr><td colspan="7" class="text-center text-muted py-4">Sin ganancias en el periodo.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div></div>
<div class="mt-3"><?= pagination_links($pg) ?></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
