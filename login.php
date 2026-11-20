<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) redirect('index.php');

$error = null;
if (is_post()) {
    csrf_check();
    $error = attempt_login((string)input('usuario', ''), (string)($_POST['password'] ?? ''));
    if ($error === null) redirect('index.php');
}
$empresa = setting('empresa_nombre', 'Sistema de Préstamos');
$logo = setting('empresa_logo');
$page_title = 'Iniciar sesión';
require __DIR__ . '/includes/header.php';
?>
<div class="login-box mx-auto">
  <div class="text-center mb-4">
    <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="Logo" style="max-height:90px;max-width:200px" class="mb-3"><?php endif; ?>
    <h1 class="h4"><?= e($empresa) ?></h1>
    <p class="text-muted">Sistema de gestión de préstamos</p>
  </div>
  <div class="card card-body">
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label">Usuario</label><input name="usuario" class="form-control" required autofocus value="<?= e(input('usuario', '')) ?>"></div>
      <div class="mb-3"><label class="form-label">Contraseña</label><input name="password" type="password" class="form-control" required></div>
      <button class="btn btn-primary w-100">Entrar</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
