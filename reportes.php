<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('reportes.ver');

$tipoRep = input('rep', 'con') === 'sin' ? 'sin' : 'con';
[$defIni, $defFin] = periodo_rango('mes');
$desde = input_date('desde', $defIni);
$hasta = input_date('hasta', $defFin);
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$gracia = (int)setting('dias_gracia', 0);
$limite = date_add_days(today(), -$gracia);

$prestamos = q_all("SELECT p.*, c.nombres, c.apellidos, c.cedula, c.celular, c.indice_credito
                      FROM prestamos p JOIN clientes c ON c.id = p.cliente_id
                     WHERE p.estado = 'activo' ORDER BY c.apellidos, c.nombres");
$filas = [];
foreach ($prestamos as $p) {
    if ($p['tipo'] === 'redito') redito_sincronizar((int)$p['id']);
    $cuotas = q_all('SELECT * FROM cuotas WHERE prestamo_id = ? AND fecha_vencimiento BETWEEN ? AND ? ORDER BY fecha_vencimiento', [$p['id'], $desde, $hasta]);
    if (!$cuotas) continue;
    $atrasos = 0; $maxDias = 0; $montoPend = 0.0; $reditos = 0;
    foreach ($cuotas as $c) {
        $dias = 0;
        if ($c['estado'] === 'pendiente' && $c['fecha_vencimiento'] < $limite) {
            $dias = days_between($c['fecha_vencimiento'], today());
            $montoPend += (float)$c['monto'] - (float)$c['pagado'];
        } elseif ($c['estado'] !== 'pendiente') {
            $dias = (int)$c['dias_atraso'];
        }
        if ($c['estado'] === 'pospuesta') $reditos++;
        if ($dias > 0) { $atrasos++; $maxDias = max($maxDias, $dias); }
    }
    $tieneAtraso = $atrasos > 0;
    if (($tipoRep === 'con') !== $tieneAtraso) continue;
    $filas[] = $p + ['cuotas_rango' => count($cuotas), 'atrasos' => $atrasos, 'max_dias' => $maxDias, 'monto_pend' => $montoPend, 'reditos' => $reditos, 'res' => prestamo_resumen($p)];
}
if ($tipoRep === 'con') usort($filas, fn($a, $b) => $b['max_dias'] <=> $a['max_dias']);

audit('reporte', 'reportes', null, 'Reporte de préstamos ' . ($tipoRep === 'con' ? 'con' : 'sin') . " atrasos $desde a $hasta");
$page_title = $tipoRep === 'con' ? 'Préstamos activos con atrasos' : 'Préstamos activos sin atrasos';
require __DIR__ . '/includes/header.php';
?>
<ul class="nav nav-tabs mb-3 no-print">
  <li class="nav-item"><a class="nav-link<?= $tipoRep === 'con' ? ' active' : '' ?>" href="?rep=con&desde=<?= $desde ?>&hasta=<?= $hasta ?>">Con atrasos</a></li>
  <li class="nav-item"><a class="nav-link<?= $tipoRep === 'sin' ? ' active' : '' ?>" href="?rep=sin&desde=<?= $desde ?>&hasta=<?= $hasta ?>">Sin atrasos</a></li>
</ul>
<form class="row g-2 mb-3 align-items-end no-print">
  <input type="hidden" name="rep" value="<?= $tipoRep ?>">
  <div class="col-md-3"><label class="form-label small">Desde</label><input type="date" name="desde" value="<?= $desde ?>" class="form-control"></div>
  <div class="col-md-3"><label class="form-label small">Hasta</label><input type="date" name="hasta" value="<?= $hasta ?>" class="form-control"></div>
  <div class="col-md-2"><button class="btn btn-primary w-100">Generar</button></div>
  <div class="col-md-2"><button type="button" onclick="window.print()" class="btn btn-outline-secondary w-100"><i class="bi bi-printer"></i> Imprimir</button></div>
</form>
<p class="text-muted small">Préstamos activos con pagos que vencían entre <?= fdate($desde) ?> y <?= fdate($hasta) ?>. <?= $tipoRep === 'con' ? 'Se considera atraso una cuota/rédito vencido sin pagar o pagado después de su vencimiento' . ($gracia ? " (con $gracia días de gracia)" : '') . '.' : 'Todos los pagos del rango se hicieron a tiempo o aún no han vencido.' ?></p>

<div class="card"><div class="table-responsive">
<table class="table table-sm table-hover mb-0">
  <thead><tr><th>Préstamo</th><th>Cliente</th><th>Celular</th><th>Tipo</th><th>Venc. en rango</th>
  <?php if ($tipoRep === 'con'): ?><th>Con atraso</th><th>Máx. días</th><th>Rédito (pospuestas)</th><th class="text-end">Vencido sin pagar</th><?php endif; ?>
  <th class="text-end">Balance</th><th>Índice</th></tr></thead>
  <tbody>
  <?php $tPend = 0; $tBal = 0; foreach ($filas as $f): $tPend += $f['monto_pend']; $tBal += $f['res']['balance']; ?>
    <tr class="clickable" data-href="prestamo_ver.php?id=<?= $f['id'] ?>">
      <td><?= e($f['codigo']) ?></td>
      <td><?= e($f['nombres'] . ' ' . $f['apellidos']) ?></td>
      <td><?= e($f['celular']) ?></td>
      <td><?= $f['tipo'] === 'san' ? 'San' : 'Rédito' ?> · <?= e($f['frecuencia']) ?></td>
      <td><?= $f['cuotas_rango'] ?></td>
      <?php if ($tipoRep === 'con'): ?>
        <td class="text-danger"><?= $f['atrasos'] ?></td><td><?= $f['max_dias'] ?></td><td><?= $f['reditos'] ?></td>
        <td class="text-end"><?= money($f['monto_pend']) ?></td>
      <?php endif; ?>
      <td class="text-end"><?= money($f['res']['balance']) ?></td>
      <td><?= credito_badge($f['indice_credito']) ?></td>
    </tr>
  <?php endforeach; if (!$filas): ?>
    <tr><td colspan="11" class="text-center text-muted py-4">No hay préstamos para este criterio.</td></tr>
  <?php else: ?>
  </tbody><tfoot class="fw-semibold"><tr><td colspan="<?= $tipoRep === 'con' ? 8 : 5 ?>"><?= count($filas) ?> préstamo(s)</td>
    <?php if ($tipoRep === 'con'): ?><td class="text-end"><?= money($tPend) ?></td><?php endif; ?>
    <td class="text-end"><?= money($tBal) ?></td><td></td></tr></tfoot>
  <?php endif; ?>
</table>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
