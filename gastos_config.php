<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('gastos.configurar');

if (is_post()) {
    csrf_check();
    $a = input('accion');
    try {
        if ($a === 'categoria') {
            $nombre = (string)input('nombre', '');
            if ($nombre === '') throw new RuntimeException('Indique el nombre.');
            $cid = db_insert('gasto_categorias', ['nombre' => $nombre]);
            audit('crear', 'gasto_categorias', (string)$cid, "Categoría de gasto '$nombre'");
        } elseif ($a === 'toggle_categoria') {
            $c = q_one('SELECT * FROM gasto_categorias WHERE id = ?', [input_int('id')]);
            q('UPDATE gasto_categorias SET activo = 1 - activo WHERE id = ?', [$c['id']]);
            audit('editar', 'gasto_categorias', (string)$c['id'], "Categoría '{$c['nombre']}' " . ($c['activo'] ? 'desactivada' : 'activada'));
        } elseif ($a === 'concepto') {
            $var = input('es_variable') === '1' ? 1 : 0;
            $monto = input_money('monto_fijo');
            $row = [
                'categoria_id' => input_int('categoria_id'),
                'nombre' => (string)input('nombre', ''),
                'es_variable' => $var,
                'monto_fijo' => $monto > 0 ? $monto : null,
            ];
            if ($row['nombre'] === '' || !$row['categoria_id']) throw new RuntimeException('Indique categoría y nombre.');
            if (!$var && !$row['monto_fijo']) throw new RuntimeException('Un concepto de monto fijo requiere el monto.');
            $id = input_int('id');
            if ($id) {
                $antes = q_one('SELECT * FROM gasto_conceptos WHERE id = ?', [$id]);
                db_update('gasto_conceptos', $row, $id);
                [$x, $y] = audit_diff($antes, $row);
                audit('editar', 'gasto_conceptos', (string)$id, "Concepto '{$row['nombre']}'", $x, $y);
            } else {
                $id = db_insert('gasto_conceptos', $row);
                audit('crear', 'gasto_conceptos', (string)$id, "Concepto '{$row['nombre']}'", null, $row);
            }
        } elseif ($a === 'toggle_concepto') {
            $c = q_one('SELECT * FROM gasto_conceptos WHERE id = ?', [input_int('id')]);
            q('UPDATE gasto_conceptos SET activo = 1 - activo WHERE id = ?', [$c['id']]);
            audit('editar', 'gasto_conceptos', (string)$c['id'], "Concepto '{$c['nombre']}' " . ($c['activo'] ? 'desactivado' : 'activado'));
        }
        flash('success', 'Guardado.');
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('gastos_config.php');
}

$categorias = q_all('SELECT * FROM gasto_categorias ORDER BY nombre');
$conceptos = q_all('SELECT gc.*, c.nombre categoria FROM gasto_conceptos gc JOIN gasto_categorias c ON c.id = gc.categoria_id ORDER BY c.nombre, gc.nombre');
$edit = input_int('editar') ? q_one('SELECT * FROM gasto_conceptos WHERE id = ?', [input_int('editar')]) : null;

$page_title = 'Categorías y conceptos de gasto';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card card-body">
      <h3 class="h6">Categorías</h3>
      <form method="post" class="d-flex gap-2 mb-3"><?= csrf_field() ?><input type="hidden" name="accion" value="categoria">
        <input name="nombre" class="form-control form-control-sm" placeholder="Nueva categoría" required><button class="btn btn-sm btn-primary">Agregar</button></form>
      <ul class="list-group list-group-flush">
        <?php foreach ($categorias as $c): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center px-0">
            <span class="<?= $c['activo'] ? '' : 'text-muted text-decoration-line-through' ?>"><?= e($c['nombre']) ?></span>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="toggle_categoria"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-link"><?= $c['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <div class="col-lg-8">
    <form method="post" class="card card-body mb-3">
      <?= csrf_field() ?><input type="hidden" name="accion" value="concepto"><input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
      <h3 class="h6"><?= $edit ? 'Editar concepto' : 'Nuevo concepto prefijado' ?></h3>
      <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small">Categoría</label><select name="categoria_id" class="form-select form-select-sm" required>
          <?php foreach ($categorias as $c): ?><option value="<?= $c['id'] ?>" <?= ($edit['categoria_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small">Concepto</label><input name="nombre" class="form-control form-control-sm" required value="<?= e($edit['nombre'] ?? '') ?>" placeholder="Ej: Alquiler local"></div>
        <div class="col-md-2"><label class="form-label small">Tipo de monto</label><select name="es_variable" class="form-select form-select-sm">
          <option value="1" <?= ($edit['es_variable'] ?? 1) ? 'selected' : '' ?>>Variable</option><option value="0" <?= isset($edit) && !$edit['es_variable'] ? 'selected' : '' ?>>Fijo</option></select></div>
        <div class="col-md-2"><label class="form-label small">Monto fijo / sugerido</label><input type="number" step="0.01" name="monto_fijo" class="form-control form-control-sm" value="<?= e($edit['monto_fijo'] ?? '') ?>"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Guardar</button></div>
      </div>
      <div class="small text-muted mt-2">Fijo: el monto se aplica automáticamente. Variable: se escribe al registrar el gasto (el monto, si se indica, es sólo sugerido).</div>
    </form>
    <div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Categoría</th><th>Concepto</th><th>Tipo</th><th class="text-end">Monto</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($conceptos as $c): ?>
        <tr class="<?= $c['activo'] ? '' : 'text-muted' ?>"><td><?= e($c['categoria']) ?></td><td><?= e($c['nombre']) ?></td><td><?= $c['es_variable'] ? 'Variable' : 'Fijo' ?></td>
          <td class="text-end"><?= $c['monto_fijo'] !== null ? money($c['monto_fijo']) : '—' ?></td>
          <td class="text-nowrap"><a href="?editar=<?= $c['id'] ?>" class="btn btn-sm btn-link">Editar</a>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="toggle_concepto"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-link"><?= $c['activo'] ? 'Desactivar' : 'Activar' ?></button></form></td></tr>
      <?php endforeach; if (!$conceptos): ?><tr><td colspan="5" class="text-center text-muted py-3">Sin conceptos.</td></tr><?php endif; ?>
      </tbody></table></div></div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
