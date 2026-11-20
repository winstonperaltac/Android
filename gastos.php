<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('gastos.gestionar');

if (is_post()) {
    csrf_check();
    $accion = input('accion');
    if ($accion === 'crear') {
        $catId = input_int('categoria_id');
        $conId = input_int('concepto_id') ?: null;
        $concepto = $conId ? q_one('SELECT * FROM gasto_conceptos WHERE id = ? AND categoria_id = ?', [$conId, $catId]) : null;
        $monto = input_money('monto');
        if ($concepto && !$concepto['es_variable'] && $concepto['monto_fijo'] !== null) {
            $monto = (float)$concepto['monto_fijo'];
        }
        $fecha = input_date('fecha', today());
        if (!q_val('SELECT id FROM gasto_categorias WHERE id = ?', [$catId]) || $monto <= 0) {
            flash('danger', 'Seleccione una categoría e indique un monto válido.');
        } else {
            $desc = (string)input('descripcion', '');
            $gid = db_insert('gastos', [
                'fecha' => $fecha, 'categoria_id' => $catId, 'concepto_id' => $concepto['id'] ?? null,
                'descripcion' => $desc ?: null, 'monto' => $monto, 'usuario_id' => current_user()['id'],
            ]);
            $label = ($concepto['nombre'] ?? 'Gasto') . ($desc ? " — $desc" : '');
            movimiento_registrar('gasto', 'gasto', -$monto, $label, $fecha, ['gasto_id' => $gid]);
            audit('crear', 'gastos', (string)$gid, "Gasto $label " . money($monto), null, ['fecha' => $fecha, 'monto' => $monto, 'categoria_id' => $catId, 'concepto_id' => $conId]);
            flash('success', 'Gasto registrado.');
        }
    } elseif ($accion === 'eliminar' && is_admin()) {
        $g = q_one('SELECT * FROM gastos WHERE id = ?', [input_int('id')]);
        if ($g) {
            q('DELETE FROM movimientos WHERE gasto_id = ?', [$g['id']]);
            q('DELETE FROM gastos WHERE id = ?', [$g['id']]);
            audit('eliminar', 'gastos', (string)$g['id'], 'Eliminación de gasto ' . money($g['monto']), $g, null);
            flash('success', 'Gasto eliminado.');
        }
    }
    redirect('gastos.php?' . http_build_query(array_intersect_key($_GET, array_flip(['desde', 'hasta', 'cat']))));
}

[$defIni, $defFin] = periodo_rango('mes');
$desde = input_date('desde', $defIni);
$hasta = input_date('hasta', $defFin);
$cat = input_int('cat');
$where = 'g.fecha BETWEEN ? AND ?'; $params = [$desde, $hasta];
if ($cat) { $where .= ' AND g.categoria_id = ?'; $params[] = $cat; }

$categorias = q_all('SELECT * FROM gasto_categorias WHERE activo = 1 ORDER BY nombre');
$conceptos = q_all('SELECT * FROM gasto_conceptos WHERE activo = 1 ORDER BY nombre');
$rows = q_all("SELECT g.*, c.nombre categoria, gc.nombre concepto, u.usuario FROM gastos g
                 JOIN gasto_categorias c ON c.id = g.categoria_id
            LEFT JOIN gasto_conceptos gc ON gc.id = g.concepto_id
            LEFT JOIN usuarios u ON u.id = g.usuario_id
                WHERE $where ORDER BY g.fecha DESC, g.id DESC", $params);
$porCat = q_all("SELECT c.nombre, SUM(g.monto) s FROM gastos g JOIN gasto_categorias c ON c.id = g.categoria_id WHERE $where GROUP BY c.nombre ORDER BY s DESC", $params);
$total = array_sum(array_column($rows, 'monto'));

$page_title = 'Gastos operativos';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-4">
    <form method="post" class="card card-body">
      <?= csrf_field() ?><input type="hidden" name="accion" value="crear">
      <h3 class="h6">Registrar gasto</h3>
      <div class="mb-2"><label class="form-label small">Fecha</label><input type="date" name="fecha" value="<?= today() ?>" class="form-control form-control-sm"></div>
      <div class="mb-2"><label class="form-label small">Categoría</label>
        <select name="categoria_id" id="categoria" class="form-select form-select-sm" required>
          <option value="">Seleccione…</option>
          <?php foreach ($categorias as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="mb-2"><label class="form-label small">Concepto</label>
        <select name="concepto_id" id="concepto" class="form-select form-select-sm"><option value="">— Otro / libre —</option></select></div>
      <div class="mb-2"><label class="form-label small">Monto</label><input type="number" step="0.01" min="0.01" name="monto" id="monto" class="form-control form-control-sm" required></div>
      <div class="mb-2"><label class="form-label small">Descripción</label><input name="descripcion" class="form-control form-control-sm" maxlength="200"></div>
      <button class="btn btn-primary btn-sm">Guardar gasto</button>
      <?php if (can('gastos.configurar')): ?><a href="gastos_config.php" class="small mt-2">Configurar categorías y conceptos</a><?php endif; ?>
    </form>
    <div class="card card-body mt-3">
      <h3 class="h6">Por categoría</h3>
      <?php foreach ($porCat as $pc): ?><div class="d-flex justify-content-between small"><span><?= e($pc['nombre']) ?></span><span><?= money($pc['s']) ?></span></div><?php endforeach; ?>
      <hr class="my-2"><div class="d-flex justify-content-between fw-semibold"><span>Total</span><span><?= money($total) ?></span></div>
    </div>
  </div>
  <div class="col-lg-8">
    <form class="row g-2 mb-2 align-items-end no-print">
      <div class="col-md-4"><label class="form-label small">Desde</label><input type="date" name="desde" value="<?= $desde ?>" class="form-control form-control-sm"></div>
      <div class="col-md-4"><label class="form-label small">Hasta</label><input type="date" name="hasta" value="<?= $hasta ?>" class="form-control form-control-sm"></div>
      <div class="col-md-2"><select name="cat" class="form-select form-select-sm"><option value="">Todas</option><?php foreach ($categorias as $c): ?><option value="<?= $c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Filtrar</button></div>
    </form>
    <div class="card"><div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead><tr><th>Fecha</th><th>Categoría</th><th>Concepto</th><th>Descripción</th><th class="text-end">Monto</th><th>Usuario</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $g): ?>
          <tr><td><?= fdate($g['fecha']) ?></td><td><?= e($g['categoria']) ?></td><td><?= e($g['concepto'] ?? '—') ?></td><td><?= e($g['descripcion']) ?></td>
          <td class="text-end"><?= money($g['monto']) ?></td><td><?= e($g['usuario']) ?></td>
          <?php if (is_admin()): ?><td><form method="post" data-confirm="¿Eliminar este gasto?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button class="btn btn-link btn-sm text-danger p-0"><i class="bi bi-trash"></i></button></form></td><?php endif; ?></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin gastos en el periodo.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>
<script>
(function () {
  const conceptos = <?= json_encode($conceptos) ?>;
  const cat = document.getElementById('categoria'), con = document.getElementById('concepto'), monto = document.getElementById('monto');
  cat.addEventListener('change', () => {
    con.innerHTML = '<option value="">— Otro / libre —</option>';
    conceptos.filter(c => String(c.categoria_id) === cat.value).forEach(c => {
      const o = document.createElement('option');
      o.value = c.id; o.textContent = c.nombre + (c.es_variable == 1 ? ' (variable)' : ' (fijo)');
      o.dataset.fijo = c.es_variable == 1 ? '' : (c.monto_fijo || '');
      o.dataset.sugerido = c.monto_fijo || '';
      con.appendChild(o);
    });
    con.dispatchEvent(new Event('change'));
  });
  con.addEventListener('change', () => {
    const o = con.selectedOptions[0];
    if (o && o.dataset.fijo) { monto.value = o.dataset.fijo; monto.readOnly = true; }
    else { monto.readOnly = false; if (o && o.dataset.sugerido) monto.value = o.dataset.sugerido; }
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
