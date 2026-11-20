<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('auditoria.ver');

$desde = input_date('desde', date_add_days(today(), -30));
$hasta = input_date('hasta', today());
$usuarioId = input_int('usuario_id');
$modulo = (string)input('modulo', '');
$accion = (string)input('accion', '');
$buscar = (string)input('q', '');

$where = 'a.fecha BETWEEN ? AND ?';
$params = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];
if ($usuarioId) { $where .= ' AND a.usuario_id = ?'; $params[] = $usuarioId; }
if ($modulo !== '') { $where .= ' AND a.modulo = ?'; $params[] = $modulo; }
if ($accion !== '') { $where .= ' AND a.accion = ?'; $params[] = $accion; }
if ($buscar !== '') { $where .= ' AND (a.descripcion LIKE ? OR a.registro_id = ?)'; $params[] = "%$buscar%"; $params[] = $buscar; }

$total = (int)q_val("SELECT COUNT(*) FROM auditoria a WHERE $where", $params);
$pg = paginate($total, 50);
$rows = q_all("SELECT a.* FROM auditoria a WHERE $where ORDER BY a.id DESC LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);
$usuarios = q_all('SELECT id, usuario FROM usuarios ORDER BY usuario');
$modulos = array_column(q_all('SELECT DISTINCT modulo FROM auditoria ORDER BY modulo'), 'modulo');
$acciones = array_column(q_all('SELECT DISTINCT accion FROM auditoria ORDER BY accion'), 'accion');

$colores = ['eliminar' => 'danger', 'anular' => 'danger', 'login_fallido' => 'warning', 'acceso_denegado' => 'warning', 'editar' => 'info', 'crear' => 'success', 'pago' => 'primary', 'cambiar_estado' => 'danger'];
$page_title = 'Auditoría del sistema';
require __DIR__ . '/includes/header.php';
?>
<p class="text-muted small">Registro inalterable de todas las acciones realizadas por los usuarios. Sólo visible para el administrador.</p>
<form class="row g-2 mb-3 align-items-end">
  <div class="col-md-2"><label class="form-label small">Desde</label><input type="date" name="desde" value="<?= $desde ?>" class="form-control form-control-sm"></div>
  <div class="col-md-2"><label class="form-label small">Hasta</label><input type="date" name="hasta" value="<?= $hasta ?>" class="form-control form-control-sm"></div>
  <div class="col-md-2"><label class="form-label small">Usuario</label><select name="usuario_id" class="form-select form-select-sm"><option value="">Todos</option><?php foreach ($usuarios as $u): ?><option value="<?= $u['id'] ?>" <?= $usuarioId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['usuario']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small">Módulo</label><select name="modulo" class="form-select form-select-sm"><option value="">Todos</option><?php foreach ($modulos as $m): ?><option <?= $modulo === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small">Acción</label><select name="accion" class="form-select form-select-sm"><option value="">Todas</option><?php foreach ($acciones as $a): ?><option <?= $accion === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small">Texto / ID</label><div class="input-group input-group-sm"><input name="q" value="<?= e($buscar) ?>" class="form-control"><button class="btn btn-primary"><i class="bi bi-search"></i></button></div></div>
</form>
<div class="small text-muted mb-2"><?= $total ?> registro(s)</div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 small">
  <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Módulo</th><th>ID</th><th>Descripción</th><th>IP</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="text-nowrap"><?= fdatetime($r['fecha']) ?></td>
      <td><?= e($r['usuario'] ?? '—') ?></td>
      <td><span class="badge bg-<?= $colores[$r['accion']] ?? 'secondary' ?>"><?= e($r['accion']) ?></span></td>
      <td><?= e($r['modulo']) ?></td><td><?= e($r['registro_id']) ?></td>
      <td><?= e($r['descripcion']) ?></td><td><?= e($r['ip']) ?></td>
      <td><?php if ($r['datos_antes'] || $r['datos_despues']): ?><button class="btn btn-link btn-sm p-0" data-bs-toggle="collapse" data-bs-target="#d<?= $r['id'] ?>">Datos</button><?php endif; ?></td>
    </tr>
    <?php if ($r['datos_antes'] || $r['datos_despues']): ?>
    <tr class="collapse" id="d<?= $r['id'] ?>"><td colspan="8" class="bg-light">
      <div class="row"><div class="col-md-6"><strong>Antes</strong><pre class="small mb-0" style="white-space:pre-wrap"><?= e($r['datos_antes'] ? json_encode(json_decode($r['datos_antes']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—') ?></pre></div>
      <div class="col-md-6"><strong>Después</strong><pre class="small mb-0" style="white-space:pre-wrap"><?= e($r['datos_despues'] ? json_encode(json_decode($r['datos_despues']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—') ?></pre></div></div>
      <div class="text-muted mt-1"><?= e($r['user_agent']) ?></div>
    </td></tr>
    <?php endif; ?>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">Sin registros.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<div class="mt-3"><?= pagination_links($pg) ?></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
