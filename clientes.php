<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('clientes.ver');

$buscar = (string)input('q', '');
$where = 'WHERE 1=1';
$params = [];
if ($buscar !== '') {
    $where .= ' AND (c.cedula LIKE ? OR c.nombres LIKE ? OR c.apellidos LIKE ? OR CONCAT(c.nombres," ",c.apellidos) LIKE ? OR c.celular LIKE ?)';
    $like = '%' . $buscar . '%';
    $params = [$like, $like, $like, $like, $like];
}
$total = (int)q_val("SELECT COUNT(*) FROM clientes c $where", $params);
$pg = paginate($total);
$rows = q_all(
    "SELECT c.*, (SELECT COUNT(*) FROM prestamos p WHERE p.cliente_id = c.id AND p.estado = 'activo') activos
       FROM clientes c $where ORDER BY c.apellidos, c.nombres LIMIT {$pg['limit']} OFFSET {$pg['offset']}",
    $params
);

$page_title = 'Clientes';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <form class="d-flex gap-2 flex-grow-1" method="get">
    <input name="q" class="form-control" placeholder="Buscar por cédula, nombre o celular" value="<?= e($buscar) ?>">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
  </form>
  <?php if (can('clientes.editar')): ?><a href="cliente_form.php" class="btn btn-primary"><i class="bi bi-person-plus"></i> Nuevo cliente</a><?php endif; ?>
</div>
<div class="card">
<div class="table-responsive">
<table class="table table-hover mb-0">
  <thead><tr><th></th><th>Cédula</th><th>Nombre</th><th>Celular</th><th>Préstamos activos</th><th>Índice de crédito</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): ?>
    <tr class="clickable" data-href="cliente_ver.php?id=<?= $c['id'] ?>">
      <td><?php if ($c['foto']): ?><img src="<?= e($c['foto']) ?>" class="client-thumb" alt=""><?php else: ?><span class="client-thumb d-inline-flex align-items-center justify-content-center"><i class="bi bi-person"></i></span><?php endif; ?></td>
      <td><?= e($c['cedula']) ?></td>
      <td><?= e($c['nombres'] . ' ' . $c['apellidos']) ?><?= $c['activo'] ? '' : ' <span class="badge bg-secondary">Inactivo</span>' ?></td>
      <td><?= e($c['celular']) ?></td>
      <td><?= (int)$c['activos'] ?></td>
      <td><?= credito_badge($c['indice_credito']) ?></td>
    </tr>
  <?php endforeach; if (!$rows): ?>
    <tr><td colspan="6" class="text-center text-muted py-4">No se encontraron clientes.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
</div>
<div class="mt-3"><?= pagination_links($pg) ?></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
