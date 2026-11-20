<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();

if (is_post()) {
    csrf_check();
    $row = q_one('SELECT password_hash FROM usuarios WHERE id = ?', [$u['id']]);
    $actual = (string)($_POST['actual'] ?? '');
    $nueva = (string)($_POST['nueva'] ?? '');
    if (!password_verify($actual, $row['password_hash'])) {
        flash('danger', 'La contraseña actual no es correcta.');
    } elseif (strlen($nueva) < 8 || $nueva !== ($_POST['confirmar'] ?? '')) {
        flash('danger', 'La nueva contraseña debe tener al menos 8 caracteres y coincidir con la confirmación.');
    } else {
        q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
        audit('cambiar_clave', 'usuarios', (string)$u['id'], 'El usuario cambió su contraseña');
        flash('success', 'Contraseña actualizada.');
    }
    redirect('perfil.php');
}
$page_title = 'Cambiar mi contraseña';
require __DIR__ . '/includes/header.php';
?>
<div class="card card-body" style="max-width:480px">
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-2"><label class="form-label">Contraseña actual</label><input type="password" name="actual" class="form-control" required></div>
    <div class="mb-2"><label class="form-label">Nueva contraseña</label><input type="password" name="nueva" class="form-control" minlength="8" required></div>
    <div class="mb-3"><label class="form-label">Confirmar</label><input type="password" name="confirmar" class="form-control" minlength="8" required></div>
    <button class="btn btn-primary">Guardar</button>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
