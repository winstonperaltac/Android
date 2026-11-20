<?php
/**
 * Configuración desde variables de entorno (Render, Docker, etc.).
 * Si existe config/config.php tiene prioridad; si no, se usan estas variables:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_SSL (1 = conexión cifrada),
 *   DB_SSL_CA (certificado CA en formato PEM, p. ej. el "CA certificate" de Aiven),
 *   DB_SSL_VERIFY (1 = exigir verificación del certificado),
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
            'ssl_ca' => env_ca_file(env_get('DB_SSL_CA')),
            'ssl_verify' => env_get('DB_SSL_VERIFY', '0') === '1',
        ],
        'timezone' => env_get('APP_TIMEZONE', 'America/Santo_Domingo'),
        'session_timeout' => (int)env_get('SESSION_TIMEOUT', '3600'),
        'debug' => env_get('APP_DEBUG', '0') === '1',
        'trust_proxy' => env_get('TRUST_PROXY', '0') === '1',
        'from_env' => true,
    ];
}

/**
 * Convierte el certificado CA recibido en una variable de entorno en un archivo temporal.
 * Acepta el texto PEM completo o una ruta a un archivo existente.
 */
function env_ca_file(?string $ca): ?string
{
    if (!$ca) return null;
    if (strpos($ca, 'BEGIN CERTIFICATE') === false) {
        return is_readable($ca) ? $ca : null;
    }
    // Se reconstruye el PEM aunque el panel haya convertido los saltos de línea en espacios o "\n" literales
    $pem = '';
    if (preg_match_all('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', str_replace('\\n', "\n", $ca), $m)) {
        foreach ($m[1] as $b64) {
            $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $b64);
            $pem .= "-----BEGIN CERTIFICATE-----\n" . chunk_split($b64, 64, "\n") . "-----END CERTIFICATE-----\n";
        }
    }
    if ($pem === '') return null;
    $file = sys_get_temp_dir() . '/db-ca-' . md5($pem) . '.pem';
    if (!is_file($file)) {
        file_put_contents($file, $pem);
        @chmod($file, 0644);
    }
    return $file;
}

/** Carga la configuración: archivo config.php o variables de entorno. */
function config_load(string $root): ?array
{
    $file = $root . '/config/config.php';
    if (file_exists($file)) return require $file;
    return config_from_env();
}

/**
 * Opciones PDO para conexiones cifradas (proveedores MySQL en la nube).
 *  - Con certificado CA válido (DB_SSL_CA): cifrado + verificación del servidor.
 *  - Sin CA válido: cifrado sin verificar el certificado (equivale a ssl-mode=REQUIRED),
 *    salvo que DB_SSL_VERIFY=1 exija la verificación.
 */
function pdo_ssl_options(array $db): array
{
    if (empty($db['ssl'])) return [];
    $opts = [];
    $ca = (!empty($db['ssl_ca']) && is_readable($db['ssl_ca'])) ? $db['ssl_ca'] : null;
    $verify = $ca !== null || !empty($db['ssl_verify']);
    if (!$ca) {
        foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'] as $sys) {
            if (is_readable($sys)) { $ca = $sys; break; }
        }
    }
    if ($ca) $opts[PDO::MYSQL_ATTR_SSL_CA] = $ca;
    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $verify;
    return $opts;
}

/** Descripción del modo SSL para mostrar en el instalador. */
function db_ssl_estado(array $db): string
{
    if (empty($db['ssl'])) return 'sin cifrado';
    $raw = env_get('DB_SSL_CA');
    if (!empty($db['ssl_ca'])) return 'cifrado; certificado DB_SSL_CA reconocido (se verifica el servidor)';
    if ($raw) return 'ATENCIÓN: DB_SSL_CA está definido pero no se reconoce como certificado; ' . (!empty($db['ssl_verify']) ? 'no se podrá conectar' : 'se conecta cifrado sin verificar el certificado');
    return !empty($db['ssl_verify']) ? 'cifrado con verificación, pero falta DB_SSL_CA' : 'cifrado sin verificar el certificado (DB_SSL_CA no definido)';
}
