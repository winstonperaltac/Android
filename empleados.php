<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('empleados.gestionar');

if (is_post()) {
    csrf_check();
    $a = input('accion');
    try {
        if ($a === 'guardar') {
            $row = [
                'cedula' => (string)input('cedula', '') ?: null,
                'nombre' => (string)input('nombre', ''),
                'cargo' => (string)input('cargo', '') ?: null,
                'telefono' => (string)input('telefono', '') ?: null,
                'sueldo' => input_money('sueldo'),
                'frecuencia_pago' => in_array(input('frecuencia_pago'), ['semanal', 'quincenal', 'mensual'], true) ? input('frecuencia_pago') : 'quincenal',
                'fecha_ingreso' => input_date('fecha_ingreso'),
                'activo' => isset($_POST['activo']) ? 1 : 0,
            ];
            if ($row['nombre'] === '') throw new RuntimeException('Indique el nombre.');
            $id = input_int('id');
            if ($id) {
                $antes = q_one('SELECT * FROM empleados WHERE id = ?', [$id]);
                db_update('empleados', $row, $id);
                [$x, $y] = audit_diff($antes, $row);
                audit('editar', 'empleados', (string)$id, "Empleado {$row['nombre']}", $x, $y);
            } else {
                $id = db_insert('empleados', $row);
                audit('crear', 'empleados', (string)$id, "Empleado {$row['nombre']} con sueldo " . money($row['sueldo']), null, $row);
            }
            flash('success', 'Empleado guardado.');
        } elseif ($a === 'pagar') {
            $emp = q_one('SELECT * FROM empleados WHERE id = ? AND activo = 1', [input_int('empleado_id')]);
            if (!$emp) throw new RuntimeException('Empleado no válido.');
            $monto = input_money('monto') ?: (float)$emp['sueldo'];
            $fecha = input_date('fecha', today());
            $periodo = (string)input('periodo', '');
            $disp = finanzas_totales()['ganancia_neta'];
            if ($monto > $disp && !isset($_POST['forzar'])) {
                throw new RuntimeException('La ganancia neta disponible (' . money($disp) . ') no cubre este sueldo. Marque "pagar de todas formas" si desea continuar.');
            }
            $nid = db_insert('nomina_pagos', ['empleado_id' => $emp['id'], 'fecha' => $fecha, 'periodo' => $periodo ?: null, 'monto' => $monto, 'notas' => (string)input('notas', '') ?: null, 'usuario_id' => current_user()['id']]);
            movimiento_registrar('sueldo', 'nomina', -$monto, "Sueldo {$emp['nombre']}" . ($periodo ? " ($periodo)" : ''), $fecha, ['nomina_id' => $nid]);
            audit('pago_sueldo', 'nomina', (string)$nid, "Pago de sueldo a {$emp['nombre']}: " . money($monto) . " $periodo");
            flash('success', 'Sueldo pagado y descontado de las ganancias.');
        } elseif ($a === 'eliminar_pago' && is_admin()) {
            $n = q_one('SELECT * FROM nomina_pagos WHERE id = ?', [input_int('id')]);
            if ($n) {
                q('DELETE FROM movimientos WHERE nomina_id = ?', [$n['id']]);
                q('DELETE FROM nomina_pagos WHERE id = ?', [$n['id']]);
                audit('eliminar', 'nomina', (string)$n['id'], 'Eliminación de pago de sueldo ' . money($n['monto']), $n, null);
                flash('success', 'Pago de sueldo eliminado.');
            }
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('empleados.php');
}

$empleados = q_all('SELECT e.*, (SELECT MAX(fecha) FROM nomina_pagos n WHERE n.empleado_id = e.id) ultimo_pago FROM empleados e ORDER BY e.activo DESC, e.nombre');
$edit = input_int('editar') ? q_one('SELECT * FROM empleados WHERE id = ?', [input_int('editar')]) : null;
$pagos = q_all('SELECT n.*, e.nombre FROM nomina_pagos n JOIN empleados e ON e.id = n.empleado_id ORDER BY n.fecha DESC, n.id DESC LIMIT 50');
$nominaMensual = 0.0;
foreach ($empleados as $e) {
    if (!$e['activo']) continue;
    $f = ['semanal' => 52 / 12, 'quincenal' => 2, 'mensual' => 1][$e['frecuencia_pago']];
    $nominaMensual += (float)$e['sueldo'] * $f;
}
$disp = finanzas_totales()['ganancia_neta'];

$page_title = 'Empleados y nómina';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card card-body stat-card"><div class="label">Nómina mensual estimada</div><div class="value"><?= money($nominaMensual) ?></div></div></div>
  <div class="col-md-4"><div class="card card-body stat-card"><div class="label">Ganancia neta disponible</div><div class="value text-success"><?= money($disp) ?></div><small class="text-muted">Los sueldos se pagan de las ganancias</small></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-4">
    <form method="post" class="card card-body">
      <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
      <h3 class="h6"><?= $edit ? 'Editar empleado' : 'Nuevo empleado' ?></h3>
      <div class="mb-2"><label class="form-label small">Nombre</label><input name="nombre" class="form-control form-control-sm" required value="<?= e($edit['nombre'] ?? '') ?>"></div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small">Cédula</label><input name="cedula" class="form-control form-control-sm" value="<?= e($edit['cedula'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label small">Teléfono</label><input name="telefono" class="form-control form-control-sm" value="<?= e($edit['telefono'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label small">Cargo</label><input name="cargo" class="form-control form-control-sm" value="<?= e($edit['cargo'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label small">Ingreso</label><input type="date" name="fecha_ingreso" class="form-control form-control-sm" value="<?= e($edit['fecha_ingreso'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label small">Sueldo</label><input type="number" step="0.01" name="sueldo" class="form-control form-control-sm" required value="<?= e($edit['sueldo'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label small">Frecuencia</label><select name="frecuencia_pago" class="form-select form-select-sm">
          <?php foreach (['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'] as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['frecuencia_pago'] ?? 'quincenal') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="activo" id="act" <?= ($edit['activo'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label small" for="act">Activo</label></div>
      <button class="btn btn-primary btn-sm">Guardar</button>
      <?php if ($edit): ?><a href="empleados.php" class="btn btn-link btn-sm">Cancelar</a><?php endif; ?>
    </form>
  </div>
  <div class="col-lg-8">
    <div class="card mb-3"><div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Empleado</th><th>Cargo</th><th class="text-end">Sueldo</th><th>Frecuencia</th><th>Último pago</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($empleados as $e): ?>
        <tr class="<?= $e['activo'] ? '' : 'text-muted' ?>">
          <td><?= e($e['nombre']) ?></td><td><?= e($e['cargo']) ?></td><td class="text-end"><?= money($e['sueldo']) ?></td><td><?= e($e['frecuencia_pago']) ?></td><td><?= fdate($e['ultimo_pago']) ?: '—' ?></td>
          <td class="text-nowrap"><a href="?editar=<?= $e['id'] ?>" class="btn btn-sm btn-link">Editar</a>
          <?php if ($e['activo']): ?><button class="btn btn-sm btn-outline-success" data-bs-toggle="collapse" data-bs-target="#pay<?= $e['id'] ?>">Pagar</button><?php endif; ?></td>
        </tr>
        <?php if ($e['activo']): ?>
        <tr class="collapse" id="pay<?= $e['id'] ?>"><td colspan="6" class="bg-light">
          <form method="post" class="row g-2 align-items-end" data-confirm="¿Registrar el pago de sueldo?">
            <?= csrf_field() ?><input type="hidden" name="accion" value="pagar"><input type="hidden" name="empleado_id" value="<?= $e['id'] ?>">
            <div class="col-md-2"><label class="form-label small">Monto</label><input type="number" step="0.01" name="monto" value="<?= e($e['sueldo']) ?>" class="form-control form-control-sm"></div>
            <div class="col-md-2"><label class="form-label small">Fecha</label><input type="date" name="fecha" value="<?= today() ?>" class="form-control form-control-sm"></div>
            <div class="col-md-3"><label class="form-label small">Periodo</label><input name="periodo" placeholder="Ej: 1ra quincena nov" class="form-control form-control-sm"></div>
            <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="forzar" id="f<?= $e['id'] ?>"><label class="form-check-label small" for="f<?= $e['id'] ?>">Pagar aunque no alcance la ganancia</label></div></div>
            <div class="col-md-2"><button class="btn btn-sm btn-success w-100">Pagar</button></div>
          </form>
        </td></tr>
        <?php endif; ?>
      <?php endforeach; if (!$empleados): ?><tr><td colspan="6" class="text-center text-muted py-3">Sin empleados.</td></tr><?php endif; ?>
      </tbody></table></div></div>

    <div class="card"><div class="card-header bg-white fw-semibold">Pagos de nómina recientes</div><div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Fecha</th><th>Empleado</th><th>Periodo</th><th class="text-end">Monto</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($pagos as $n): ?>
        <tr><td><?= fdate($n['fecha']) ?></td><td><?= e($n['nombre']) ?></td><td><?= e($n['periodo']) ?></td><td class="text-end"><?= money($n['monto']) ?></td>
        <?php if (is_admin()): ?><td><form method="post" data-confirm="¿Eliminar este pago de nómina?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar_pago"><input type="hidden" name="id" value="<?= $n['id'] ?>"><button class="btn btn-link btn-sm text-danger p-0"><i class="bi bi-trash"></i></button></form></td><?php endif; ?></tr>
      <?php endforeach; if (!$pagos): ?><tr><td colspan="5" class="text-center text-muted py-3">Sin pagos.</td></tr><?php endif; ?>
      </tbody></table></div></div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
