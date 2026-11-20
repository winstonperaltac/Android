<?php
declare(strict_types=1);

/** Escapa HTML. */
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($v): string
{
    return setting('moneda', 'RD$') . ' ' . number_format((float)$v, 2, '.', ',');
}

function fdate(?string $d): string
{
    if (!$d) return '';
    $t = strtotime($d);
    return $t ? date('d/m/Y', $t) : '';
}

function fdatetime(?string $d): string
{
    if (!$d) return '';
    $t = strtotime($d);
    return $t ? date('d/m/Y h:i A', $t) : '';
}

function today(): string
{
    return date('Y-m-d');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $k, $default = null)
{
    $v = $_POST[$k] ?? $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function input_int(string $k, int $default = 0): int
{
    $v = input($k);
    return is_numeric($v) ? (int)$v : $default;
}

function input_money(string $k): float
{
    $v = str_replace([',', ' '], '', (string)input($k, '0'));
    return is_numeric($v) ? round((float)$v, 2) : 0.0;
}

function input_date(string $k, ?string $default = null): ?string
{
    $v = (string)input($k, '');
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : $default;
}

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('Token de seguridad inválido. Recargue la página e intente de nuevo.');
    }
}

function client_ip(): string
{
    global $CONFIG;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // Detrás de un proxy (Render, Cloudflare...) la IP real viene en X-Forwarded-For
    if (!empty($CONFIG['trust_proxy']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return substr($ip, 0, 45);
}

/**
 * Sube una imagen de forma segura y la guarda en la base de datos
 * (así no se pierde en servidores con disco temporal como Render).
 * La imagen se redimensiona a un máximo de $maxLado píxeles.
 * Devuelve la referencia ("imagen.php?id=N") para usar como src, o null.
 */
function upload_image(string $field, string $subdir, int $maxBytes = 5242880, bool $publico = false, int $maxLado = 800): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir el archivo.');
    }
    if ($f['size'] > $maxBytes) {
        throw new RuntimeException('La imagen excede el tamaño máximo permitido (' . round($maxBytes / 1048576) . ' MB).');
    }
    $info = @getimagesize($f['tmp_name']);
    $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_GIF => 'imagecreatefromgif', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    if (!$info || !isset($loaders[$info[2]]) || !function_exists($loaders[$info[2]])) {
        throw new RuntimeException('El archivo debe ser una imagen JPG, PNG, GIF o WEBP.');
    }
    $src = @$loaders[$info[2]]($f['tmp_name']);
    if (!$src) throw new RuntimeException('No se pudo leer la imagen.');
    [$w, $h] = [imagesx($src), imagesy($src)];
    $esc = min(1, $maxLado / max($w, $h));
    $nw = max(1, (int)round($w * $esc)); $nh = max(1, (int)round($h * $esc));
    $dst = imagecreatetruecolor($nw, $nh);
    $conAlfa = in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
    if ($conAlfa) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    if ($conAlfa) { imagepng($dst, null, 8); $mime = 'image/png'; }
    else { imagejpeg($dst, null, 85); $mime = 'image/jpeg'; }
    $datos = ob_get_clean();
    imagedestroy($src); imagedestroy($dst);

    $id = db_insert('imagenes', ['categoria' => $subdir, 'mime' => $mime, 'datos' => $datos, 'publico' => $publico ? 1 : 0]);
    return 'imagen.php?id=' . $id;
}

/** Elimina una imagen guardada (en base de datos o, en instalaciones antiguas, en disco). */
function image_delete(?string $ref): void
{
    if (!$ref) return;
    if (preg_match('/^imagen\.php\?id=(\d+)$/', $ref, $m)) {
        q('DELETE FROM imagenes WHERE id = ?', [(int)$m[1]]);
    } elseif (strpos($ref, 'uploads/') === 0 && is_file(APP_ROOT . '/' . $ref)) {
        @unlink(APP_ROOT . '/' . $ref);
    }
}

/** Suma días a una fecha Y-m-d. */
function date_add_days(string $date, int $days): string
{
    return (new DateTime($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

function days_between(string $from, string $to): int
{
    return (int)(new DateTime($from))->diff(new DateTime($to))->format('%r%a');
}

/** Paginación sencilla. */
function paginate(int $total, int $perPage = 25): array
{
    $page = max(1, input_int('p', 1));
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'limit' => $perPage, 'total' => $total];
}

function pagination_links(array $pg): string
{
    if ($pg['pages'] <= 1) return '';
    $qs = $_GET;
    $html = '<nav><ul class="pagination pagination-sm">';
    for ($i = 1; $i <= $pg['pages']; $i++) {
        if ($pg['pages'] > 12 && abs($i - $pg['page']) > 3 && $i !== 1 && $i !== $pg['pages']) {
            if (abs($i - $pg['page']) === 4) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            continue;
        }
        $qs['p'] = $i;
        $html .= '<li class="page-item' . ($i === $pg['page'] ? ' active' : '') . '"><a class="page-link" href="?' . e(http_build_query($qs)) . '">' . $i . '</a></li>';
    }
    return $html . '</ul></nav>';
}

function frecuencia_dias(string $frecuencia): int
{
    return $frecuencia === 'semanal' ? 7 : 15;
}

function badge_estado(string $estado): string
{
    $map = [
        'activo' => 'primary', 'saldado' => 'success', 'cancelado' => 'secondary',
        'incobrable' => 'danger', 'pendiente' => 'warning', 'pagada' => 'success', 'pospuesta' => 'info',
    ];
    return '<span class="badge bg-' . ($map[$estado] ?? 'secondary') . '">' . e(ucfirst($estado)) . '</span>';
}
