<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('clientes.ver');

$id = input_int('id');
$c = q_one('SELECT * FROM clientes WHERE id = ?', [$id]);
if (!$c) { flash('warning', 'Cliente no encontrado.'); redirect('clientes.php'); }

// Eliminación de registros del historial crediticio (sólo administrador)
if (is_post()) {
    csrf_check();
    require_perm('historial.eliminar');
    $accion = input('accion');
    if ($accion === 'eliminar_evento') {
        $ev = q_one('SELECT * FROM historial_crediticio WHERE id = ? AND cliente_id = ?', [input_int('evento_id'), $id]);
        if ($ev) {
            q('DELETE FROM historial_crediticio WHERE id = ?', [$ev['id']]);
            audit('eliminar', 'historial_crediticio', (string)$ev['id'], "Eliminado evento '{$ev['evento']}' del historial de {$c['nombres']} {$c['apellidos']}. Motivo: " . input('motivo', ''), $ev, null);
            flash('success', 'Registro eliminado del historial.');
        }
    } elseif ($accion === 'eliminar_antiguos') {
        $antes = input_date('antes_de');
        $soloNegativos = isset($_POST['solo_negativos']);
        if ($antes) {
            $sql = 'SELECT * FROM historial_crediticio WHERE cliente_id = ? AND fecha < ?' . ($soloNegativos ? " AND (dias_atraso > 0 OR evento IN ('redito_parcial','prestamo_incobrable'))" : '');
            $evs = q_all($sql, [$id, $antes]);
            foreach ($evs as $ev) q('DELETE FROM historial_crediticio WHERE id = ?', [$ev['id']]);
            audit('eliminar', 'historial_crediticio', null, count($evs) . " eventos anteriores a $antes eliminados del historial de {$c['nombres']} {$c['apellidos']}. Motivo: " . input('motivo', ''), $evs, null);
            flash('success', count($evs) . ' registros eliminados.');
        }
    }
    credito_recalcular($id);
    redirect('cliente_ver.php?id=' . $id . '#historial');
}

$credito = credito_calcular($id);
if ((int)$c['indice_credito'] !== $credito['score']) credito_recalcular($id);
[$catLabel, $catColor] = credito_categoria($credito['score']);
$prestamos = q_all('SELECT * FROM prestamos WHERE cliente_id = ? ORDER BY id DESC', [$id]);
$historial = q_all('SELECT * FROM historial_crediticio WHERE cliente_id = ? ORDER BY fecha DESC, id DESC', [$id]);
$pos = ($credito['score'] - CREDITO_MIN) / (CREDITO_MAX - CREDITO_MIN) * 100;

audit('consultar', 'clientes', (string)$id, "Consulta de ficha de {$c['nombres']} {$c['apellidos']}");

$page_title = 'Ficha del cliente';
require __DIR__ . '/includes/header.php';
$eventLabels = ['pago_puntual' => 'Pago puntual', 'pago_tardio' => 'Pago tardío', 'redito_parcial' => 'Pagó rédito (cuota pospuesta)', 'prestamo_saldado' => 'Préstamo saldado', 'prestamo_incobrable' => 'Préstamo incobrable'];
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card card-body h-100">
      <div class="d-flex gap-3 flex-wrap">
        <?php if ($c['foto']): ?><img src="<?= e($c['foto']) ?>" class="client-photo" alt="Foto"><?php else: ?><div class="client-photo d-flex align-items-center justify-content-center"><i class="bi bi-person fs-1 text-muted"></i></div><?php endif; ?>
        <div class="flex-grow-1">
          <h2 class="h4 mb-1"><?= e($c['nombres'] . ' ' . $c['apellidos']) ?> <?= $c['activo'] ? '' : '<span class="badge bg-secondary">Inactivo</span>' ?></h2>
          <div class="text-muted mb-2">Cédula: <?= e($c['cedula']) ?></div>
          <div class="row small g-2">
            <div class="col-sm-12"><i class="bi bi-geo-alt"></i> <?= e($c['direccion'] ?: '—') ?></div>
            <div class="col-sm-4"><strong>Celular:</strong> <?= e($c['celular'] ?: '—') ?></div>
            <div class="col-sm-4"><strong>Casa:</strong> <?= e($c['telefono_casa'] ?: '—') ?></div>
            <div class="col-sm-4"><strong>Trabajo:</strong> <?= e($c['telefono_trabajo'] ?: '—') ?></div>
            <div class="col-sm-8"><strong>Garante:</strong> <?= e($c['garante_nombre'] ?: '—') ?></div>
            <div class="col-sm-4"><strong>Tel. garante:</strong> <?= e($c['garante_telefono'] ?: '—') ?></div>
            <?php if ($c['notas']): ?><div class="col-12"><strong>Notas:</strong> <?= nl2br(e($c['notas'])) ?></div><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="mt-3 no-print">
        <?php if (can('clientes.editar')): ?><a href="cliente_form.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> Editar</a><?php endif; ?>
        <?php if (can('prestamos.crear') && $c['activo']): ?><a href="prestamo_nuevo.php?cliente_id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> Nuevo préstamo</a><?php endif; ?>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Imprimir</button>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card card-body h-100">
      <div class="text-muted small">Índice de crédito</div>
      <div class="d-flex align-items-end gap-2 mb-2">
        <div class="score-big text-<?= $catColor ?>"><?= $credito['score'] ?></div>
        <div class="fs-5 text-<?= $catColor ?>"><?= e($catLabel) ?></div>
      </div>
      <div class="score-bar mb-1"><div class="marker" style="left: calc(<?= round($pos, 1) ?>% - 2px)"></div></div>
      <div class="d-flex justify-content-between small text-muted mb-3"><span>300</span><span>580</span><span>670</span><span>740</span><span>850</span></div>
      <table class="table table-sm small mb-0">
        <?php $names = ['pagos' => 'Historial de pagos', 'deuda' => 'Endeudamiento', 'antiguedad' => 'Antigüedad', 'completados' => 'Préstamos completados', 'simultaneo' => 'Crédito simultáneo'];
        foreach ($credito['factores'] as $k => $f): ?>
          <tr title="<?= e($f['detalle']) ?>"><td><?= $names[$k] ?> <span class="text-muted">(<?= $f['peso'] ?>%)</span><div class="text-muted" style="font-size:.75rem"><?= e($f['detalle']) ?></div></td>
          <td style="width:90px"><div class="progress" style="height:8px"><div class="progress-bar" style="width:<?= $f['valor'] ?>%"></div></div></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header bg-white fw-semibold">Préstamos</div>
  <div class="table-responsive">
  <table class="table table-hover mb-0">
    <thead><tr><th>Código</th><th>Tipo</th><th>Inicio</th><th class="text-end">Monto</th><th class="text-end">Balance</th><th>Atraso</th><th>Estado</th></tr></thead>
    <tbody>
    <?php foreach ($prestamos as $p): $r = prestamo_resumen($p); ?>
      <tr class="clickable" data-href="prestamo_ver.php?id=<?= $p['id'] ?>">
        <td><?= e($p['codigo']) ?></td>
        <td><?= $p['tipo'] === 'san' ? 'San' : 'Rédito' ?> · <?= e($p['frecuencia']) ?></td>
        <td><?= fdate($p['fecha_inicio']) ?></td>
        <td class="text-end"><?= money($p['monto']) ?></td>
        <td class="text-end"><?= $p['estado'] === 'activo' ? money($r['balance']) : '—' ?></td>
        <td><?= $r['atrasadas'] && $p['estado'] === 'activo' ? '<span class="text-danger">' . $r['atrasadas'] . ' venc. / ' . $r['dias_atraso'] . ' días</span>' : '—' ?></td>
        <td><?= badge_estado($p['estado']) ?></td>
      </tr>
    <?php endforeach; if (!$prestamos): ?>
      <tr><td colspan="7" class="text-center text-muted py-3">Sin préstamos.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card mt-3" id="historial">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
    <span>Historial crediticio</span>
    <?php if (can('historial.eliminar') && $historial): ?>
      <button class="btn btn-sm btn-outline-danger no-print" data-bs-toggle="collapse" data-bs-target="#limpiar">Eliminar registros antiguos</button>
    <?php endif; ?>
  </div>
  <?php if (can('historial.eliminar')): ?>
  <div class="collapse" id="limpiar">
    <form method="post" class="card-body border-bottom bg-light row g-2 align-items-end" data-confirm="¿Eliminar los registros seleccionados del historial? Esta acción queda auditada.">
      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar_antiguos">
      <div class="col-md-3"><label class="form-label small">Anteriores a</label><input type="date" name="antes_de" class="form-control form-control-sm" required value="<?= date_add_days(today(), -365) ?>"></div>
      <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="solo_negativos" id="sn" checked><label for="sn" class="form-check-label small">Sólo eventos negativos</label></div></div>
      <div class="col-md-4"><label class="form-label small">Motivo</label><input name="motivo" class="form-control form-control-sm" required></div>
      <div class="col-md-2"><button class="btn btn-sm btn-danger w-100">Eliminar</button></div>
    </form>
  </div>
  <?php endif; ?>
  <div class="table-responsive">
  <table class="table table-sm mb-0">
    <thead><tr><th>Fecha</th><th>Evento</th><th>Detalle</th><th>Días atraso</th><th class="text-end">Monto</th><?php if (can('historial.eliminar')): ?><th class="no-print"></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($historial as $h):
      $neg = $h['dias_atraso'] > 0 || in_array($h['evento'], ['redito_parcial', 'prestamo_incobrable'], true); ?>
      <tr class="<?= $neg ? 'table-warning' : '' ?>">
        <td><?= fdate($h['fecha']) ?></td>
        <td><?= e($eventLabels[$h['evento']] ?? $h['evento']) ?></td>
        <td><?php if ($h['prestamo_id']): ?><a href="prestamo_ver.php?id=<?= $h['prestamo_id'] ?>"><?= e($h['descripcion']) ?></a><?php else: ?><?= e($h['descripcion']) ?><?php endif; ?></td>
        <td><?= (int)$h['dias_atraso'] ?: '—' ?></td>
        <td class="text-end"><?= money($h['monto']) ?></td>
        <?php if (can('historial.eliminar')): ?>
        <td class="no-print">
          <form method="post" class="d-inline" data-confirm="¿Eliminar este registro del historial crediticio?">
            <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar_evento"><input type="hidden" name="evento_id" value="<?= $h['id'] ?>"><input type="hidden" name="motivo" value="Eliminación individual">
            <button class="btn btn-link btn-sm text-danger p-0" title="Eliminar"><i class="bi bi-trash"></i></button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; if (!$historial): ?>
      <tr><td colspan="6" class="text-center text-muted py-3">Sin historial.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
