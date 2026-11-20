<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('prestamos.ver');
$pg = q_one('SELECT pg.*, u.nombre usuario_nombre FROM pagos pg LEFT JOIN usuarios u ON u.id = pg.usuario_id WHERE pg.id = ?', [input_int('id')]);
if (!$pg) redirect('prestamos.php');
$p = prestamo_get((int)$pg['prestamo_id']);
$r = prestamo_resumen($p);
$page_title = 'Recibo ' . $pg['recibo'];
require __DIR__ . '/includes/header.php';
?>
<div class="card card-body mx-auto" style="max-width:420px">
  <div class="text-center">
    <?php if (setting('empresa_logo')): ?><img src="<?= e(setting('empresa_logo')) ?>" style="max-height:60px" alt=""><?php endif; ?>
    <div class="fw-bold"><?= e(setting('empresa_nombre')) ?></div>
    <div class="small"><?= e(setting('empresa_rnc') ? 'RNC ' . setting('empresa_rnc') : '') ?> <?= e(setting('empresa_telefono')) ?></div>
    <div class="small"><?= e(setting('empresa_direccion')) ?></div>
    <hr>
    <div class="fw-semibold">RECIBO DE PAGO <?= e($pg['recibo']) ?></div>
    <?php if ($pg['anulado']): ?><div class="text-danger fw-bold">ANULADO</div><?php endif; ?>
  </div>
  <table class="table table-sm small mt-2 mb-2">
    <tr><td>Fecha</td><td class="text-end"><?= fdate($pg['fecha']) ?></td></tr>
    <tr><td>Cliente</td><td class="text-end"><?= e($p['nombres'] . ' ' . $p['apellidos']) ?></td></tr>
    <tr><td>Préstamo</td><td class="text-end"><?= e($p['codigo']) ?> (<?= $p['tipo'] === 'san' ? 'San' : 'Rédito' ?>)</td></tr>
    <tr><td>Concepto</td><td class="text-end"><?= e($pg['detalle']) ?></td></tr>
    <?php if ($pg['capital'] > 0): ?><tr><td>Capital</td><td class="text-end"><?= money($pg['capital']) ?></td></tr><?php endif; ?>
    <?php if ($pg['interes'] > 0): ?><tr><td>Interés</td><td class="text-end"><?= money($pg['interes']) ?></td></tr><?php endif; ?>
    <?php if ($pg['redito'] > 0): ?><tr><td>Rédito</td><td class="text-end"><?= money($pg['redito']) ?></td></tr><?php endif; ?>
    <tr class="fw-bold"><td>TOTAL PAGADO</td><td class="text-end"><?= money($pg['monto']) ?></td></tr>
    <tr><td>Balance pendiente</td><td class="text-end"><?= $p['estado'] === 'activo' ? money($r['balance']) : 'SALDADO' ?></td></tr>
    <?php if ($r['proximo'] && $p['estado'] === 'activo'): ?><tr><td>Próximo pago</td><td class="text-end"><?= fdate($r['proximo']) ?> · <?= money($r['proximo_monto']) ?></td></tr><?php endif; ?>
    <tr><td>Atendido por</td><td class="text-end"><?= e($pg['usuario_nombre']) ?></td></tr>
  </table>
  <div class="d-flex gap-2 no-print">
    <button onclick="window.print()" class="btn btn-primary flex-grow-1"><i class="bi bi-printer"></i> Imprimir</button>
    <a href="prestamo_ver.php?id=<?= $p['id'] ?>" class="btn btn-secondary">Volver</a>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
