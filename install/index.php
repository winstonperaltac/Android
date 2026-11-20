<?php
/**
 * Instalador web: crea las tablas y el usuario administrador.
 *  - Hosting tradicional: pide los datos de MySQL y escribe config/config.php.
 *  - Render / Docker: toma los datos de MySQL de las variables de entorno (DB_HOST, ...)
 *    y exige la clave INSTALL_KEY para evitar que un tercero instale primero.
 * Se bloquea si ya existe config/install.lock o si la base de datos ya tiene usuarios.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/includes/env_config.php';
$lock = $root . '/config/install.lock';
$errors = [];
$done = false;
$envConfig = file_exists($root . '/config/config.php') ? null : config_from_env();
$installKey = env_get('INSTALL_KEY');

$yaInstalado = file_exists($lock);
if (!$yaInstalado && $envConfig) {
    try {
        $c = $envConfig['db'];
        $pdoChk = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + pdo_ssl_options($c));
        $yaInstalado = (int)$pdoChk->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1146') === false) {
            $msg = 'No se pudo conectar a la base de datos configurada en las variables de entorno: ' . $e->getMessage();
            if (stripos($e->getMessage(), 'SSL') !== false && !empty($c['ssl_ca'])) {
                $msg .= '. El certificado de DB_SSL_CA no corresponde a este servidor: cópielo de nuevo desde Aiven ("CA certificate") o borre la variable DB_SSL_CA para conectar cifrado sin verificar.';
            }
            $errors[] = $msg;
        }
    }
}
if ($yaInstalado) {
    exit('<h3 style="font-family:sans-serif">El sistema ya está instalado. <a href="../login.php">Ir al inicio de sesión</a></h3>');
}

function v(string $k, string $d = ''): string { return htmlspecialchars((string)($_POST[$k] ?? $d), ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!$errors || !$envConfig)) {
    $db = $envConfig ? $envConfig['db'] : [
        'host' => trim($_POST['db_host'] ?? 'localhost'),
        'port' => (int)($_POST['db_port'] ?? 3306),
        'name' => trim($_POST['db_name'] ?? ''),
        'user' => trim($_POST['db_user'] ?? ''),
        'pass' => (string)($_POST['db_pass'] ?? ''),
    ];
    if ($envConfig && (!$installKey || !hash_equals($installKey, (string)($_POST['install_key'] ?? '')))) {
        $errors[] = $installKey ? 'La clave de instalación (INSTALL_KEY) no es correcta.' : 'Defina la variable de entorno INSTALL_KEY para poder instalar.';
    }
    $empresa = trim($_POST['empresa'] ?? '');
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminName = trim($_POST['admin_name'] ?? '');
    $adminPass = (string)($_POST['admin_pass'] ?? '');

    if ($db['name'] === '' || $db['user'] === '') $errors[] = 'Indique nombre y usuario de la base de datos.';
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $adminUser)) $errors[] = 'El usuario administrador debe tener 3-50 caracteres (letras, números, . _ -).';
    if (strlen($adminPass) < 8) $errors[] = 'La contraseña del administrador debe tener al menos 8 caracteres.';
    if ($adminName === '') $errors[] = 'Indique el nombre del administrador.';
    if (!$envConfig && !is_writable($root . '/config')) $errors[] = 'La carpeta config/ no tiene permisos de escritura.';

    if (!$errors) {
        try {
            $pdo = new PDO("mysql:host={$db['host']};port={$db['port']};charset=utf8mb4", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + pdo_ssl_options($db));
            try {
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $db['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            } catch (PDOException $e) {
                // En hosting compartido normalmente la BD ya existe y no se puede crear: se ignora.
            }
            $pdo->exec('USE `' . str_replace('`', '', $db['name']) . '`');
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            // Quita comentarios y ejecuta sentencia por sentencia
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            $sql = preg_replace('/--[^\n\']*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
                $pdo->exec($stmt);
            }
            $st = $pdo->prepare('INSERT INTO usuarios (usuario, nombre, password_hash, rol) VALUES (?,?,?,\'admin\')');
            $st->execute([$adminUser, $adminName, password_hash($adminPass, PASSWORD_DEFAULT)]);
            if ($empresa !== '') {
                $pdo->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'empresa_nombre'")->execute([$empresa]);
            }
            $pdo->prepare('INSERT INTO auditoria (usuario, accion, modulo, descripcion, ip) VALUES (?,?,?,?,?)')
                ->execute([$adminUser, 'instalar', 'sistema', 'Instalación del sistema', $_SERVER['REMOTE_ADDR'] ?? '']);

            if (!$envConfig) {
            $config = [
                'db' => $db,
                'timezone' => $_POST['timezone'] ?? 'America/Santo_Domingo',
                'session_timeout' => 3600,
                'debug' => false,
            ];
            $php = "<?php\n// Generado por el instalador el " . date('Y-m-d H:i') . "\nreturn " . var_export($config, true) . ";\n";
            file_put_contents($root . '/config/config.php', $php);
            @chmod($root . '/config/config.php', 0640);
            file_put_contents($lock, date('c'));
            }
            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
}
?><!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalación · Sistema de Préstamos</title>
<link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-5" style="max-width:720px">
  <h1 class="h3 mb-4">Instalación del Sistema de Préstamos</h1>
  <?php if ($done): ?>
    <div class="alert alert-success">¡Instalación completada! Por seguridad, elimine o proteja la carpeta <code>install/</code>.</div>
    <a href="../login.php" class="btn btn-primary">Ir al inicio de sesión</a>
  <?php else: ?>
    <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?= htmlspecialchars($er) ?></div><?php endforeach; ?>
    <form method="post" class="card card-body">
      <?php if ($envConfig): ?>
      <div class="alert alert-info">Base de datos tomada de las variables de entorno: <strong><?= htmlspecialchars($envConfig['db']['user'] . '@' . $envConfig['db']['host'] . '/' . $envConfig['db']['name']) ?></strong><br><small>Conexión: <?= htmlspecialchars(db_ssl_estado($envConfig['db'])) ?></small></div>
      <div class="mb-3"><label class="form-label">Clave de instalación (variable INSTALL_KEY)</label><input name="install_key" type="password" class="form-control" required></div>
      <?php else: ?>
      <h5>Base de datos MySQL</h5>
      <div class="row g-2 mb-3">
        <div class="col-md-8"><label class="form-label">Servidor</label><input name="db_host" class="form-control" value="<?= v('db_host', 'localhost') ?>"></div>
        <div class="col-md-4"><label class="form-label">Puerto</label><input name="db_port" class="form-control" value="<?= v('db_port', '3306') ?>"></div>
        <div class="col-md-4"><label class="form-label">Base de datos</label><input name="db_name" class="form-control" value="<?= v('db_name', 'prestamos') ?>" required></div>
        <div class="col-md-4"><label class="form-label">Usuario</label><input name="db_user" class="form-control" value="<?= v('db_user') ?>" required></div>
        <div class="col-md-4"><label class="form-label">Contraseña</label><input name="db_pass" type="password" class="form-control"></div>
      </div>
      <?php endif; ?>
      <h5>Empresa</h5>
      <div class="row g-2 mb-3">
        <div class="col-md-8"><label class="form-label">Nombre de la empresa</label><input name="empresa" class="form-control" value="<?= v('empresa') ?>"></div>
        <div class="col-md-4"><label class="form-label">Zona horaria</label><input name="timezone" class="form-control" value="<?= v('timezone', 'America/Santo_Domingo') ?>"></div>
      </div>
      <h5>Administrador</h5>
      <div class="row g-2 mb-3">
        <div class="col-md-4"><label class="form-label">Usuario</label><input name="admin_user" class="form-control" value="<?= v('admin_user', 'admin') ?>" required></div>
        <div class="col-md-4"><label class="form-label">Nombre</label><input name="admin_name" class="form-control" value="<?= v('admin_name', 'Administrador') ?>" required></div>
        <div class="col-md-4"><label class="form-label">Contraseña (mín. 8)</label><input name="admin_pass" type="password" class="form-control" required minlength="8"></div>
      </div>
      <button class="btn btn-primary">Instalar</button>
    </form>
  <?php endif; ?>
</div></body></html>
