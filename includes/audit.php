<?php
declare(strict_types=1);

/**
 * Registra una acción en la tabla de auditoría.
 * Cada creación, modificación, eliminación, pago, inicio de sesión, etc. pasa por aquí.
 */
function audit(
    string $accion,
    string $modulo,
    ?string $registroId = null,
    ?string $descripcion = null,
    $antes = null,
    $despues = null,
    ?int $userId = null,
    ?string $username = null
): void {
    try {
        if ($userId === null) {
            $u = function_exists('current_user') ? current_user() : null;
            $userId = $u['id'] ?? null;
            $username = $username ?? ($u['usuario'] ?? null);
        }
        q(
            'INSERT INTO auditoria (usuario_id, usuario, accion, modulo, registro_id, descripcion, datos_antes, datos_despues, ip, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $userId,
                $username,
                $accion,
                $modulo,
                $registroId,
                $descripcion !== null ? mb_substr($descripcion, 0, 255) : null,
                $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
                $despues !== null ? json_encode($despues, JSON_UNESCAPED_UNICODE) : null,
                client_ip(),
                mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]
        );
    } catch (Throwable $e) {
        error_log('Fallo en auditoría: ' . $e->getMessage());
    }
}

/** Devuelve sólo los campos que cambiaron entre dos arreglos. */
function audit_diff(array $antes, array $despues): array
{
    $a = [];
    $d = [];
    foreach ($despues as $k => $v) {
        if (!array_key_exists($k, $antes) || (string)$antes[$k] !== (string)$v) {
            $a[$k] = $antes[$k] ?? null;
            $d[$k] = $v;
        }
    }
    return [$a, $d];
}
