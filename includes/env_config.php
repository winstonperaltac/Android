<?php
/**
 * Configuración desde variables de entorno (Render, Docker, etc.).
 * Si existe config/config.php tiene prioridad; si no, se usan estas variables:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_SSL (1 = conexión cifrada),
 *   APP_TIMEZONE, SESSION_TIMEOUT, APP_DEBUG, TRUST_PROXY
 */
declare(strict_types=1);

function env_get(string $k, ?string $default = null): ?string
{
    $v = getenv($k);
    if ($v === false || $v === '') $v = $_ENV[$k] ?? $_SERVER[$k] ?? null;
    return ($v === null || $v === '') ? $default : (string)$v;
}

function config_from_env(): ?array
{
    if (!env_get('DB_HOST') || !env_get('DB_NAME')) return null;
    return [
        'db' => [
            'host' => env_get('DB_HOST'),
            'port' => (int)env_get('DB_PORT', '3306'),
            'name' => env_get('DB_NAME'),
            'user' => env_get('DB_USER', ''),
            'pass' => env_get('DB_PASS', ''),
            'ssl'  => in_array(strtolower((string)env_get('DB_SSL', '0')), ['1', 'true', 'yes', 'si', 'sí'], true),
        ],
        'timezone' => env_get('APP_TIMEZONE', 'America/Santo_Domingo'),
        'session_timeout' => (int)env_get('SESSION_TIMEOUT', '3600'),
        'debug' => env_get('APP_DEBUG', '0') === '1',
        'trust_proxy' => env_get('TRUST_PROXY', '0') === '1',
        'from_env' => true,
    ];
}

/** Carga la configuración: archivo config.php o variables de entorno. */
function config_load(string $root): ?array
{
    $file = $root . '/config/config.php';
    if (file_exists($file)) return require $file;
    return config_from_env();
}

/** Opciones PDO para conexiones cifradas (proveedores MySQL en la nube). */
function pdo_ssl_options(array $db): array
{
    if (empty($db['ssl'])) return [];
    $opts = [];
    foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'] as $ca) {
        if (is_readable($ca)) { $opts[PDO::MYSQL_ATTR_SSL_CA] = $ca; break; }
    }
    if (!empty($db['ssl_ca']) && is_readable($db['ssl_ca'])) $opts[PDO::MYSQL_ATTR_SSL_CA] = $db['ssl_ca'];
    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    return $opts;
}
