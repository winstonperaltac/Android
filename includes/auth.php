<?php
declare(strict_types=1);

/**
 * Catálogo de permisos. Los marcados como 'admin' sólo los tiene el rol administrador.
 */
const PERMISOS = [
    'clientes.ver'        => 'Ver clientes',
    'clientes.editar'     => 'Crear / editar clientes',
    'prestamos.ver'       => 'Ver préstamos',
    'prestamos.crear'     => 'Crear préstamos',
    'pagos.registrar'     => 'Registrar pagos',
    'reportes.ver'        => 'Ver reportes de préstamos',
    'ganancias.ver'       => 'Ver ganancias, ingresos y reinversión',
    'gastos.gestionar'    => 'Registrar gastos operativos',
    'gastos.configurar'   => 'Configurar categorías y conceptos de gasto',
    'empleados.gestionar' => 'Gestionar empleados y pagar sueldos',
];

/** Permisos exclusivos del administrador (no asignables). */
const PERMISOS_ADMIN = [
    'usuarios.gestionar'  => 'Gestionar usuarios y privilegios',
    'auditoria.ver'       => 'Ver auditoría del sistema',
    'configuracion'       => 'Configurar empresa',
    'historial.eliminar'  => 'Eliminar registros del historial crediticio',
    'prestamos.anular'    => 'Anular pagos / cambiar estado de préstamos',
];

const ROLES = [
    'admin'      => 'Administrador',
    'supervisor' => 'Supervisor',
    'cajero'     => 'Cajero / Cobrador',
    'consulta'   => 'Solo consulta',
];

/** Permisos por defecto al crear un usuario según su rol. */
function permisos_por_rol(string $rol): array
{
    switch ($rol) {
        case 'supervisor':
            return array_keys(PERMISOS);
        case 'cajero':
            return ['clientes.ver', 'clientes.editar', 'prestamos.ver', 'prestamos.crear', 'pagos.registrar'];
        case 'consulta':
            return ['clientes.ver', 'prestamos.ver', 'reportes.ver'];
        default:
            return [];
    }
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $user = q_one('SELECT id, usuario, nombre, email, rol, activo FROM usuarios WHERE id = ?', [$_SESSION['uid']]);
            if (!$user || !$user['activo']) {
                $user = null;
                unset($_SESSION['uid']);
            } else {
                $user['permisos'] = array_column(q_all('SELECT permiso FROM usuario_permisos WHERE usuario_id = ?', [$user['id']]), 'permiso');
            }
        }
    }
    return $user;
}

function is_admin(): bool
{
    $u = current_user();
    return $u && $u['rol'] === 'admin';
}

function can(string $perm): bool
{
    $u = current_user();
    if (!$u) return false;
    if ($u['rol'] === 'admin') return true;
    if (isset(PERMISOS_ADMIN[$perm])) return false;
    return in_array($perm, $u['permisos'], true);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function require_perm(string $perm): array
{
    $u = require_login();
    if (!can($perm)) {
        audit('acceso_denegado', 'seguridad', null, 'Intento de acceso sin permiso: ' . $perm . ' en ' . ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        $page_title = 'Acceso denegado';
        require APP_ROOT . '/includes/header.php';
        echo '<div class="alert alert-danger">No tiene privilegios para acceder a esta sección.</div>';
        require APP_ROOT . '/includes/footer.php';
        exit;
    }
    return $u;
}

/**
 * Intenta iniciar sesión. Devuelve null si tuvo éxito o un mensaje de error.
 */
function attempt_login(string $usuario, string $password): ?string
{
    $u = q_one('SELECT * FROM usuarios WHERE usuario = ?', [$usuario]);
    if (!$u) {
        password_verify($password, '$2y$10$usesomesillystringforsaltOrWhatever.Abc12345678901234'); // tiempo constante
        audit('login_fallido', 'seguridad', null, 'Usuario inexistente: ' . $usuario, null, null, null, $usuario);
        return 'Usuario o contraseña incorrectos.';
    }
    if (!$u['activo']) {
        audit('login_fallido', 'seguridad', (string)$u['id'], 'Usuario inactivo', null, null, (int)$u['id'], $u['usuario']);
        return 'El usuario está desactivado.';
    }
    if ($u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time()) {
        return 'Usuario bloqueado temporalmente por intentos fallidos. Intente más tarde.';
    }
    if (!password_verify($password, $u['password_hash'])) {
        $intentos = (int)$u['intentos_fallidos'] + 1;
        $bloqueo = $intentos >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        q('UPDATE usuarios SET intentos_fallidos = ?, bloqueado_hasta = ? WHERE id = ?', [$bloqueo ? 0 : $intentos, $bloqueo, $u['id']]);
        audit('login_fallido', 'seguridad', (string)$u['id'], 'Contraseña incorrecta' . ($bloqueo ? ' (usuario bloqueado 15 min)' : ''), null, null, (int)$u['id'], $u['usuario']);
        return 'Usuario o contraseña incorrectos.';
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['last_activity'] = time();
    q('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = ?', [$u['id']]);
    audit('login', 'seguridad', (string)$u['id'], 'Inicio de sesión', null, null, (int)$u['id'], $u['usuario']);
    return null;
}

function logout(): void
{
    $u = current_user();
    if ($u) {
        audit('logout', 'seguridad', (string)$u['id'], 'Cierre de sesión');
    }
    $_SESSION = [];
    session_destroy();
}
