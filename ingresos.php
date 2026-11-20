<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('ganancias.ver');

$periodo = in_array(input('periodo'), ['semana', 'quincena', 'mes', 'anio'], true) ? input('periodo') : 'mes';
$ref = input_date('ref', today());
[$desde, $hasta] = periodo_rango($periodo, $ref);

$res = q_one('SELECT COUNT(*) n, COALESCE(SUM(monto),0) total, COALESCE(SUM(capital),0) capital, COALESCE(SUM(interes),0) interes, COALESCE(SUM(redito),0) redito
                FROM pagos WHERE anulado = 0 AND fecha BETWEEN ? AND ?', [$desde, $hasta]);
$t = finanzas_totales($desde, $hasta);

// Desglose por día (semana/quincena/mes) o por mes (año)
$grupo = $periodo === 'anio' ? "DATE_FORMAT(fecha, '%Y-%m')" : 'fecha';
$serie = q_all("SELECT $grupo g, COUNT(*) n, SUM(monto) total, SUM(capital) capital, SUM(interes) interes, SUM(redito) redito
                  FROM pagos WHERE anulado = 0 AND fecha BETWEEN ? AND ? GROUP BY g ORDER BY g", [$desde, $hasta]);
$max = max(array_merge([1], array_map(fn($s) => (float)$s['total'], $serie)));

$nav = function (int $dir) use ($periodo, $desde, $hasta) {
    $d = $dir < 0 ? date_add_days($desde, -1) : date_add_days($hasta, 1);
    return '?periodo=' . $periodo . '&ref=' . $d;
};
$labels = ['semana' => 'Semana', 'quincena' => 'Quincena', 'mes' => 'Mes', 'anio' => 'Año'];
$page_title = 'Ingresos por periodo';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3 no-print">
  <div class="btn-group">
    <?php foreach ($labels as $k => $l): ?><a href="?periodo=<?= $k ?>&ref=<?= $ref ?>" class="btn btn-sm btn-<?= $periodo === $k ? 'primary' : 'outline-primary' ?>"><?= $l ?></a><?php endforeach; ?>
  </div>
  <a href="<?= $nav(-1) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
  <strong><?= fdate($desde) ?> — <?= fdate($hasta) ?></strong>
  <a href="<?= $nav(1) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
  <a href="?periodo=<?= $periodo ?>" class="btn btn-sm btn-link">Actual</a>
</div>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Total ingresado (<?= (int)$res['n'] ?> pagos)</div><div class="value"><?= money($res['total']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Capital recuperado</div><div class="value"><?= money($res['capital']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Interés de cuotas</div><div class="value"><?= money($res['interes']) ?></div><small class="text-muted">50% ganancia / 50% reinversión</small></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Réditos</div><div class="value"><?= money($res['redito']) ?></div><small class="text-muted">100% ganancia</small></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Ganancia</div><div class="value text-success"><?= money($t['ganancia']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Reinvertido</div><div class="value text-primary"><?= money($t['reinversion'] + $t['reinversion_manual']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Prestado (desembolsos)</div><div class="value"><?= money(-$t['desembolso']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Gastos + sueldos</div><div class="value text-danger"><?= money(-($t['gasto'] + $t['sueldo'])) ?></div></div></div>
</div>
<div class="card"><div class="table-responsive">
<table class="table table-sm mb-0">
  <thead><tr><th><?= $periodo === 'anio' ? 'Mes' : 'Día' ?></th><th>Pagos</th><th class="text-end">Capital</th><th class="text-end">Interés</th><th class="text-end">Rédito</th><th class="text-end">Total</th><th style="width:30%"></th></tr></thead>
  <tbody>
  <?php foreach ($serie as $s): ?>
    <tr>
      <td><?= $periodo === 'anio' ? e($s['g']) : fdate($s['g']) ?></td><td><?= (int)$s['n'] ?></td>
      <td class="text-end"><?= money($s['capital']) ?></td><td class="text-end"><?= money($s['interes']) ?></td><td class="text-end"><?= money($s['redito']) ?></td>
      <td class="text-end fw-semibold"><?= money($s['total']) ?></td>
      <td><div class="progress" style="height:10px"><div class="progress-bar bg-success" style="width:<?= round($s['total'] / $max * 100) ?>%"></div></div></td>
    </tr>
  <?php endforeach; if (!$serie): ?>
    <tr><td colspan="7" class="text-center text-muted py-4">Sin ingresos en este periodo.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
