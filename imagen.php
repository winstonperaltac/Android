<?php
// Sirve imágenes guardadas en la base de datos. El logo es público; las fotos de clientes requieren sesión.
require __DIR__ . '/includes/bootstrap.php';

$img = q_one('SELECT id, mime, datos, publico, creado_en FROM imagenes WHERE id = ?', [input_int('id')]);
if (!$img) {
    http_response_code(404);
    exit;
}
if (!$img['publico'] && !can('clientes.ver')) {
    http_response_code(403);
    exit;
}
$etag = '"img' . $img['id'] . '-' . strtotime($img['creado_en']) . '"';
header('Content-Type: ' . $img['mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: ' . ($img['publico'] ? 'public' : 'private') . ', max-age=86400');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . strlen($img['datos']));
echo $img['datos'];
