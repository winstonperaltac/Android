<?php
$__u = current_user();
$__empresa = setting('empresa_nombre', 'Sistema de Préstamos');
$__logo = setting('empresa_logo');
$__page = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__menu = [
    ['index.php', 'bi-speedometer2', 'Inicio', null],
    ['clientes.php', 'bi-people', 'Clientes', 'clientes.ver'],
    ['prestamos.php', 'bi-cash-coin', 'Préstamos', 'prestamos.ver'],
    ['reportes.php', 'bi-file-earmark-bar-graph', 'Reportes de atrasos', 'reportes.ver'],
    ['ganancias.php', 'bi-graph-up-arrow', 'Ganancias', 'ganancias.ver'],
    ['ingresos.php', 'bi-calendar3', 'Ingresos por periodo', 'ganancias.ver'],
    ['reinversion.php', 'bi-arrow-repeat', 'Reinversión y capital', 'ganancias.ver'],
    ['gastos.php', 'bi-receipt', 'Gastos operativos', 'gastos.gestionar'],
    ['empleados.php', 'bi-person-badge', 'Empleados y nómina', 'empleados.gestionar'],
    ['usuarios.php', 'bi-shield-lock', 'Usuarios y privilegios', 'usuarios.gestionar'],
    ['auditoria.php', 'bi-journal-text', 'Auditoría', 'auditoria.ver'],
    ['configuracion.php', 'bi-gear', 'Configuración', 'configuracion'],
];
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($page_title ?? 'Inicio') . ' · ' . $__empresa) ?></title>
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/css/app.css?v=<?= APP_VERSION ?>" rel="stylesheet">
<?php if ($__logo): ?><link rel="icon" href="<?= e($__logo) ?>"><?php endif; ?>
</head>
<body>
<?php if ($__u): ?>
<nav class="navbar navbar-dark bg-dark d-lg-none px-3">
  <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar"><i class="bi bi-list"></i></button>
  <span class="navbar-brand mb-0 h6"><?= e($__empresa) ?></span>
</nav>
<div class="d-flex">
  <aside class="offcanvas-lg offcanvas-start bg-dark text-white sidebar" id="sidebar" tabindex="-1">
    <div class="p-3 border-bottom border-secondary text-center">
      <?php if ($__logo): ?><img src="<?= e($__logo) ?>" alt="Logo" class="sidebar-logo mb-2"><?php endif; ?>
      <div class="fw-bold"><?= e($__empresa) ?></div>
    </div>
    <ul class="nav flex-column p-2">
      <?php foreach ($__menu as [$href, $icon, $label, $perm]):
        if ($perm && !can($perm)) continue; ?>
        <li class="nav-item">
          <a class="nav-link text-white<?= $__page === $href ? ' active' : '' ?>" href="<?= $href ?>"><i class="bi <?= $icon ?> me-2"></i><?= e($label) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="p-3 border-top border-secondary small mt-auto">
      <div><i class="bi bi-person-circle"></i> <?= e($__u['nombre']) ?></div>
      <div class="text-white-50"><?= e(ROLES[$__u['rol']] ?? $__u['rol']) ?></div>
      <a href="perfil.php" class="text-white-50 me-2">Mi clave</a>
      <a href="logout.php" class="text-warning">Salir</a>
    </div>
  </aside>
  <main class="flex-grow-1 p-3 p-md-4 main-content">
<?php else: ?>
  <main class="container py-5">
<?php endif; ?>
<?php foreach (flashes() as $f): ?>
  <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show"><?= e($f['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>
<?php if (!empty($page_title) && $__u): ?>
  <h1 class="h3 mb-3"><?= e($page_title) ?></h1>
<?php endif; ?>
