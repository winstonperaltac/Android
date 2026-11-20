<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('clientes.editar');

$id = input_int('id');
$cliente = $id ? q_one('SELECT * FROM clientes WHERE id = ?', [$id]) : null;
if ($id && !$cliente) redirect('clientes.php');

$campos = ['cedula', 'nombres', 'apellidos', 'direccion', 'telefono_trabajo', 'telefono_casa', 'celular', 'garante_nombre', 'garante_telefono', 'notas'];
$data = $cliente ?? array_fill_keys($campos, '');
$errors = [];

if (is_post()) {
    csrf_check();
    foreach ($campos as $c) {
        $data[$c] = mb_substr((string)input($c, ''), 0, $c === 'notas' ? 2000 : 255);
    }
    if ($data['cedula'] === '') $errors[] = 'La cédula es obligatoria.';
    if ($data['nombres'] === '' || $data['apellidos'] === '') $errors[] = 'Nombres y apellidos son obligatorios.';
    $dup = q_val('SELECT id FROM clientes WHERE cedula = ? AND id <> ?', [$data['cedula'], $id]);
    if ($dup) $errors[] = 'Ya existe un cliente con esa cédula.';

    $foto = null;
    if (!$errors) {
        try {
            $foto = upload_image('foto', 'clientes');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
    if (!$errors) {
        $row = array_intersect_key($data, array_flip($campos));
        $row = array_map(fn($v) => $v === '' ? null : $v, $row);
        $row['cedula'] = $data['cedula'];
        if ($foto) $row['foto'] = $foto;
        if ($cliente) {
            $row['activo'] = isset($_POST['activo']) ? 1 : 0;
            db_update('clientes', $row, $id);
            [$a, $d] = audit_diff($cliente, $row);
            audit('editar', 'clientes', (string)$id, "Edición de cliente {$row['nombres']} {$row['apellidos']}", $a, $d);
            if ($foto) image_delete($cliente['foto']);
            flash('success', 'Cliente actualizado.');
        } else {
            $row['creado_por'] = current_user()['id'];
            $id = db_insert('clientes', $row);
            credito_recalcular($id);
            audit('crear', 'clientes', (string)$id, "Nuevo cliente {$row['nombres']} {$row['apellidos']} ({$row['cedula']})", null, $row);
            flash('success', 'Cliente registrado.');
        }
        redirect('cliente_ver.php?id=' . $id);
    }
}

$page_title = $cliente ? 'Editar cliente' : 'Nuevo cliente';
require __DIR__ . '/includes/header.php';
foreach ($errors as $er) echo '<div class="alert alert-danger">' . e($er) . '</div>';
?>
<form method="post" enctype="multipart/form-data" class="card card-body">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-3 text-center">
      <?php if (!empty($cliente['foto'])): ?><img src="<?= e($cliente['foto']) ?>" class="client-photo mb-2" alt="Foto"><?php else: ?><div class="client-photo mx-auto mb-2 d-flex align-items-center justify-content-center"><i class="bi bi-person fs-1 text-muted"></i></div><?php endif; ?>
      <label class="form-label">Foto del cliente</label>
      <input type="file" name="foto" accept="image/*" capture="environment" class="form-control form-control-sm">
    </div>
    <div class="col-md-9">
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Cédula *</label><input name="cedula" class="form-control" required value="<?= e($data['cedula']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Nombres *</label><input name="nombres" class="form-control" required value="<?= e($data['nombres']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Apellidos *</label><input name="apellidos" class="form-control" required value="<?= e($data['apellidos']) ?>"></div>
        <div class="col-12"><label class="form-label">Dirección</label><input name="direccion" class="form-control" value="<?= e($data['direccion']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Celular</label><input name="celular" class="form-control" value="<?= e($data['celular']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Teléfono casa</label><input name="telefono_casa" class="form-control" value="<?= e($data['telefono_casa']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Teléfono trabajo</label><input name="telefono_trabajo" class="form-control" value="<?= e($data['telefono_trabajo']) ?>"></div>
        <div class="col-md-8"><label class="form-label">Nombre del garante</label><input name="garante_nombre" class="form-control" value="<?= e($data['garante_nombre']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Teléfono del garante</label><input name="garante_telefono" class="form-control" value="<?= e($data['garante_telefono']) ?>"></div>
        <div class="col-12"><label class="form-label">Notas</label><textarea name="notas" class="form-control" rows="2"><?= e($data['notas']) ?></textarea></div>
        <?php if ($cliente): ?>
        <div class="col-12"><div class="form-check"><input type="checkbox" class="form-check-input" name="activo" id="activo" <?= $cliente['activo'] ? 'checked' : '' ?>><label for="activo" class="form-check-label">Cliente activo</label></div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="mt-3">
    <button class="btn btn-primary">Guardar</button>
    <a href="<?= $cliente ? 'cliente_ver.php?id=' . $id : 'clientes.php' ?>" class="btn btn-secondary">Cancelar</a>
  </div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
