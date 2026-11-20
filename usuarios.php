<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_perm('usuarios.gestionar');

if (is_post()) {
    csrf_check();
    try {
        $id = input_int('id');
        $usuario = (string)input('usuario', '');
        $rol = array_key_exists(input('rol'), ROLES) ? input('rol') : 'cajero';
        $pass = (string)($_POST['password'] ?? '');
        $row = [
            'usuario' => $usuario,
            'nombre' => (string)input('nombre', ''),
            'email' => (string)input('email', '') ?: null,
            'rol' => $rol,
            'activo' => isset($_POST['activo']) ? 1 : 0,
        ];
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $usuario)) throw new RuntimeException('Usuario inválido (3-50 caracteres: letras, números, . _ -).');
        if ($row['nombre'] === '') throw new RuntimeException('Indique el nombre.');
        if (q_val('SELECT id FROM usuarios WHERE usuario = ? AND id <> ?', [$usuario, $id])) throw new RuntimeException('Ese usuario ya existe.');
        if ($id === (int)$me['id'] && ($rol !== 'admin' || !$row['activo'])) throw new RuntimeException('No puede quitarse a sí mismo el rol de administrador ni desactivarse.');
        if ($pass !== '' && strlen($pass) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
        if (!$id && $pass === '') throw new RuntimeException('Indique una contraseña.');

        $permisos = array_values(array_intersect(array_keys(PERMISOS), (array)($_POST['permisos'] ?? [])));
        if (isset($_POST['usar_defecto'])) $permisos = permisos_por_rol($rol);

        db_tx(function () use (&$id, $row, $pass, $permisos) {
            if ($id) {
                $antes = q_one('SELECT id, usuario, nombre, email, rol, activo FROM usuarios WHERE id = ?', [$id]);
                $permAntes = array_column(q_all('SELECT permiso FROM usuario_permisos WHERE usuario_id = ?', [$id]), 'permiso');
                if ($pass !== '') $row['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
                db_update('usuarios', $row, $id);
                unset($row['password_hash']);
                [$a, $d] = audit_diff($antes, $row);
                if ($pass !== '') $d['password'] = '(cambiada)';
                sort($permAntes); $p2 = $permisos; sort($p2);
                if ($permAntes !== $p2) { $a['permisos'] = $permAntes; $d['permisos'] = $p2; }
                audit('editar', 'usuarios', (string)$id, "Edición de usuario {$row['usuario']}", $a, $d);
            } else {
                $row['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
                $id = db_insert('usuarios', $row);
                unset($row['password_hash']);
                audit('crear', 'usuarios', (string)$id, "Nuevo usuario {$row['usuario']} ({$row['rol']})", null, $row + ['permisos' => $permisos]);
            }
            q('DELETE FROM usuario_permisos WHERE usuario_id = ?', [$id]);
            foreach ($permisos as $pm) q('INSERT INTO usuario_permisos (usuario_id, permiso) VALUES (?, ?)', [$id, $pm]);
        });
        flash('success', 'Usuario guardado.');
        redirect('usuarios.php');
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
        redirect('usuarios.php?editar=' . input_int('id'));
    }
}

$usuarios = q_all('SELECT * FROM usuarios ORDER BY activo DESC, nombre');
$edit = input_int('editar') ? q_one('SELECT * FROM usuarios WHERE id = ?', [input_int('editar')]) : null;
$editPerms = $edit ? array_column(q_all('SELECT permiso FROM usuario_permisos WHERE usuario_id = ?', [$edit['id']]), 'permiso') : permisos_por_rol('cajero');

$page_title = 'Usuarios y privilegios';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" class="card card-body" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
      <h3 class="h6"><?= $edit ? 'Editar usuario' : 'Nuevo usuario' ?></h3>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small">Usuario</label><input name="usuario" class="form-control form-control-sm" required value="<?= e($edit['usuario'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label small">Nombre</label><input name="nombre" class="form-control form-control-sm" required value="<?= e($edit['nombre'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label small">Email</label><input type="email" name="email" class="form-control form-control-sm" value="<?= e($edit['email'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label small">Contraseña <?= $edit ? '(vacío = no cambiar)' : '' ?></label><input type="password" name="password" class="form-control form-control-sm" minlength="8" autocomplete="new-password" <?= $edit ? '' : 'required' ?>></div>
        <div class="col-6"><label class="form-label small">Rol</label><select name="rol" class="form-select form-select-sm">
          <?php foreach (ROLES as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['rol'] ?? 'cajero') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 pt-4"><div class="form-check"><input type="checkbox" class="form-check-input" name="activo" id="act" <?= ($edit['activo'] ?? 1) ? 'checked' : '' ?>><label for="act" class="form-check-label small">Activo</label></div></div>
      </div>
      <label class="form-label small fw-semibold">Privilegios</label>
      <div class="border rounded p-2 mb-2">
        <?php foreach (PERMISOS as $k => $l): ?>
          <div class="form-check"><input type="checkbox" class="form-check-input" name="permisos[]" value="<?= $k ?>" id="p_<?= $k ?>" <?= in_array($k, $editPerms, true) ? 'checked' : '' ?>><label for="p_<?= $k ?>" class="form-check-label small"><?= e($l) ?></label></div>
        <?php endforeach; ?>
        <hr class="my-2">
        <div class="small text-muted">Exclusivos del administrador: <?= e(implode(', ', PERMISOS_ADMIN)) ?>.</div>
      </div>
      <div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="usar_defecto" id="def"><label for="def" class="form-check-label small">Usar los privilegios por defecto del rol</label></div>
      <button class="btn btn-primary btn-sm">Guardar</button>
      <?php if ($edit): ?><a href="usuarios.php" class="btn btn-link btn-sm">Cancelar</a><?php endif; ?>
    </form>
  </div>
  <div class="col-lg-7">
    <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
      <thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Último acceso</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($usuarios as $u): ?>
        <tr class="clickable" data-href="?editar=<?= $u['id'] ?>">
          <td><?= e($u['usuario']) ?></td><td><?= e($u['nombre']) ?></td><td><?= e(ROLES[$u['rol']] ?? $u['rol']) ?></td>
          <td><?= fdatetime($u['ultimo_acceso']) ?: '—' ?></td>
          <td><?= $u['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?><?= $u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time() ? ' <span class="badge bg-danger">Bloqueado</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
