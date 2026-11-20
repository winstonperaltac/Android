<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('prestamos.ver');

$vista = input('vista', 'activos') === 'inactivos' ? 'inactivos' : 'activos';
$tipo = in_array(input('tipo'), ['san', 'redito'], true) ? input('tipo') : '';
$buscar = (string)input('q', '');

$where = $vista === 'activos' ? "p.estado = 'activo'" : "p.estado <> 'activo'";
$params = [];
if ($tipo) { $where .= ' AND p.tipo = ?'; $params[] = $tipo; }
if ($buscar !== '') {
    $where .= ' AND (p.codigo LIKE ? OR c.cedula LIKE ? OR CONCAT(c.nombres," ",c.apellidos) LIKE ?)';
    $l = "%$buscar%"; array_push($params, $l, $l, $l);
}
$rows = q_all("SELECT p.*, c.nombres, c.apellidos, c.cedula, c.indice_credito,
                      (SELECT COALESCE(SUM(monto),0) FROM pagos pg WHERE pg.prestamo_id = p.id AND pg.anulado = 0) cobrado,
                      (SELECT COALESCE(SUM(m.monto),0) FROM movimientos m WHERE m.prestamo_id = p.id AND m.tipo = 'ganancia') ganancia
                 FROM prestamos p JOIN clientes c ON c.id = p.cliente_id
                WHERE $where ORDER BY p.id DESC", $params);

$tot = ['monto' => 0, 'balance' => 0, 'cobrado' => 0, 'ganancia' => 0, 'atrasados' => 0, 'vencido' => 0];
foreach ($rows as &$r) {
    $r['res'] = prestamo_resumen($r);
    $tot['monto'] += $r['monto'];
    $tot['cobrado'] += $r['cobrado'];
    $tot['ganancia'] += $r['ganancia'];
    if ($vista === 'activos') {
        $tot['balance'] += $r['res']['balance'];
        if ($r['res']['atrasadas']) { $tot['atrasados']++; $tot['vencido'] += $r['res']['monto_atrasado']; }
    }
}
unset($r);

$page_title = $vista === 'activos' ? 'Resumen de préstamos activos' : 'Resumen de préstamos inactivos';
require __DIR__ . '/includes/header.php';
?>
<ul class="nav nav-tabs mb-3 no-print">
  <li class="nav-item"><a class="nav-link<?= $vista === 'activos' ? ' active' : '' ?>" href="?vista=activos">Activos</a></li>
  <li class="nav-item"><a class="nav-link<?= $vista === 'inactivos' ? ' active' : '' ?>" href="?vista=inactivos">Inactivos (saldados, cancelados, incobrables)</a></li>
</ul>
<form class="row g-2 mb-3 no-print" method="get">
  <input type="hidden" name="vista" value="<?= $vista ?>">
  <div class="col-md-5"><input name="q" class="form-control" placeholder="Código, cédula o nombre" value="<?= e($buscar) ?>"></div>
  <div class="col-md-3"><select name="tipo" class="form-select"><option value="">Todos los tipos</option><option value="san" <?= $tipo === 'san' ? 'selected' : '' ?>>San (cuotas)</option><option value="redito" <?= $tipo === 'redito' ? 'selected' : '' ?>>Rédito</option></select></div>
  <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filtrar</button></div>
  <div class="col-md-2"><button type="button" onclick="window.print()" class="btn btn-outline-secondary w-100"><i class="bi bi-printer"></i> Imprimir</button></div>
</form>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Préstamos</div><div class="value"><?= count($rows) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Total prestado</div><div class="value"><?= money($tot['monto']) ?></div></div></div>
  <?php if ($vista === 'activos'): ?>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Balance por cobrar</div><div class="value"><?= money($tot['balance']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Con atraso</div><div class="value text-danger"><?= $tot['atrasados'] ?></div><small class="text-muted"><?= money($tot['vencido']) ?> vencido</small></div></div>
  <?php else: ?>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Total cobrado</div><div class="value"><?= money($tot['cobrado']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card card-body stat-card"><div class="label">Ganancia generada</div><div class="value text-success"><?= money($tot['ganancia']) ?></div></div></div>
  <?php endif; ?>
</div>

<div class="card"><div class="table-responsive">
<table class="table table-hover table-sm mb-0">
  <thead><tr><th>Código</th><th>Cliente</th><th>Tipo</th><th>Inicio</th><th class="text-end">Monto</th><th class="text-end">Cobrado</th>
  <?php if ($vista === 'activos'): ?><th class="text-end">Balance</th><th>Próximo pago</th><th>Atraso</th><?php else: ?><th>Cerrado</th><th>Estado</th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $p): $r = $p['res']; ?>
    <tr class="clickable<?= $vista === 'activos' && $r['atrasadas'] ? ' table-danger' : '' ?>" data-href="prestamo_ver.php?id=<?= $p['id'] ?>">
      <td><?= e($p['codigo']) ?></td>
      <td><?= e($p['nombres'] . ' ' . $p['apellidos']) ?><div class="small"><?= credito_badge($p['indice_credito']) ?></div></td>
      <td><?= $p['tipo'] === 'san' ? 'San' : 'Rédito' ?> · <?= e($p['frecuencia']) ?></td>
      <td><?= fdate($p['fecha_inicio']) ?></td>
      <td class="text-end"><?= money($p['monto']) ?></td>
      <td class="text-end"><?= money($p['cobrado']) ?></td>
      <?php if ($vista === 'activos'): ?>
        <td class="text-end"><?= money($r['balance']) ?></td>
        <td><?= $r['proximo'] ? fdate($r['proximo']) . '<div class="small text-muted">' . money($r['proximo_monto']) . '</div>' : '—' ?></td>
        <td><?= $r['atrasadas'] ? '<span class="text-danger fw-semibold">' . $r['atrasadas'] . ' venc. · ' . $r['dias_atraso'] . ' d</span>' : '<span class="text-success">Al día</span>' ?></td>
      <?php else: ?>
        <td><?= fdatetime($p['cerrado_en']) ?></td>
        <td><?= badge_estado($p['estado']) ?></td>
      <?php endif; ?>
    </tr>
  <?php endforeach; if (!$rows): ?>
    <tr><td colspan="9" class="text-center text-muted py-4">No hay préstamos.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
