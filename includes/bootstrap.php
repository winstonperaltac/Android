<?php
/**
 * Arranque común: configuración, sesión, conexión y utilidades.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

require_once __DIR__ . '/env_config.php';
$CONFIG = config_load(APP_ROOT);
if (!$CONFIG) {
    header('Location: install/index.php');
    exit;
}

date_default_timezone_set($CONFIG['timezone'] ?? 'America/Santo_Domingo');
if (!empty($CONFIG['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

// ---------- Sesión segura ----------
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($CONFIG['trust_proxy']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('PRESTAMOSSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/loans.php';
require_once __DIR__ . '/credit.php';
require_once __DIR__ . '/finance.php';

// Con configuración por variables de entorno, la instalación se detecta en la base de datos.
if (!empty($CONFIG['from_env'])) {
    try {
        q_val('SELECT 1 FROM usuarios LIMIT 1');
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1146') !== false || stripos($e->getMessage(), "doesn't exist") !== false) {
            header('Location: install/index.php');
            exit;
        }
        throw $e;
    }
}

// Expira la sesión tras inactividad
$timeout = (int)($CONFIG['session_timeout'] ?? 3600);
if (!empty($_SESSION['uid']) && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    session_start();
    flash('warning', 'Su sesión expiró por inactividad.');
}
$_SESSION['last_activity'] = time();
