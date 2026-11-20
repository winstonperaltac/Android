<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('prestamos.ver');

$id = input_int('id');
$p = prestamo_get($id);
if (!$p) { flash('warning', 'Préstamo no encontrado.'); redirect('prestamos.php'); }

if (is_post()) {
    csrf_check();
    $accion = input('accion');
    try {
        if ($accion === 'pagar') {
            require_perm('pagos.registrar');
            $pagoId = pago_registrar($id, (string)input('modo'), input_date('fecha', today()), input_int('periodos', 1), input_money('abono'), (string)input('nota', ''));
            flash('success', 'Pago registrado correctamente.');
            redirect('recibo.php?id=' . $pagoId);
        } elseif ($accion === 'anular') {
            require_perm('prestamos.anular');
            $motivo = (string)input('motivo', '');
            if ($motivo === '') throw new RuntimeException('Indique el motivo de la anulación.');
            pago_anular(input_int('pago_id'), $motivo);
            flash('success', 'Pago anulado.');
        } elseif ($accion === 'estado') {
            require_perm('prestamos.anular');
            prestamo_cambiar_estado($id, (string)input('estado'), (string)input('motivo', ''));
            flash('success', 'Estado actualizado.');
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('prestamo_ver.php?id=' . $id);
}

$r = prestamo_resumen($p);
$p = prestamo_get($id);
$cuotas = q_all('SELECT * FROM cuotas WHERE prestamo_id = ? ORDER BY fecha_vencimiento, numero', [$id]);
$pagos = q_all('SELECT pg.*, u.usuario FROM pagos pg LEFT JOIN usuarios u ON u.id = pg.usuario_id WHERE pg.prestamo_id = ? ORDER BY pg.id DESC', [$id]);
$ultimoPago = (int)q_val('SELECT MAX(id) FROM pagos WHERE prestamo_id = ? AND anulado = 0', [$id]);
$ganancia = (float)q_val("SELECT COALESCE(SUM(monto),0) FROM movimientos WHERE prestamo_id = ? AND tipo = 'ganancia'", [$id]);
$reinv = (float)q_val("SELECT COALESCE(SUM(monto),0) FROM movimientos WHERE prestamo_id = ? AND tipo = 'reinversion'", [$id]);
$cobrado = (float)q_val('SELECT COALESCE(SUM(monto),0) FROM pagos WHERE prestamo_id = ? AND anulado = 0', [$id]);

// Montos de referencia para el formulario de pago
$pend = array_values(array_filter($cuotas, fn($c) => $c['estado'] === 'pendiente'));
$opc = [];
if ($p['estado'] === 'activo') {
    if ($p['tipo'] === 'redito') {
        $alDia = redito_pendientes_hasta_actual($id);
        $reditoAlDia = array_sum(array_map(fn($c) => (float)$c['monto'], $alDia));
        $opc = [
            'redito_periodo' => round((float)$p['capital_pendiente'] * (float)$p['tasa'] / 100, 2),
            'redito_aldia'   => round($reditoAlDia, 2),
            'periodos_aldia' => count($alDia),
            'saldar'         => round($reditoAlDia + (float)$p['capital_pendiente'], 2),
        ];
    } else {
        $opc = [
            'cuota'   => $pend ? (float)$pend[0]['monto'] : 0,
            'redito'  => $pend ? round((float)$pend[0]['monto'] / 2, 2) : 0,
            'saldar'  => round(array_sum(array_map(fn($c) => (float)$c['monto'] - (float)$c['pagado'], $pend)), 2),
            'pend'    => count($pend),
            'vencidas' => $r['atrasadas'],
        ];
    }
}

$page_title = 'Préstamo ' . $p['codigo'];
require __DIR__ . '/includes/header.php';
$gracia = (int)setting('dias_gracia', 0);
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card card-body h-100">
      <div class="d-flex justify-content-between flex-wrap">
        <div>
          <h2 class="h5 mb-1"><a href="cliente_ver.php?id=<?= $p['cliente_id'] ?>"><?= e($p['nombres'] . ' ' . $p['apellidos']) ?></a></h2>
          <div class="text-muted small">Cédula <?= e($p['cedula']) ?> · Cel. <?= e($p['celular']) ?> · <?= credito_badge($p['indice_credito']) ?></div>
        </div>
        <div><?= badge_estado($p['estado']) ?></div>
      </div>
      <hr>
      <div class="row small g-2">
        <div class="col-6 col-md-4"><strong>Tipo:</strong> <?= $p['tipo'] === 'san' ? 'San (cuotas)' : 'Rédito' ?></div>
        <div class="col-6 col-md-4"><strong>Frecuencia:</strong> <?= e(ucfirst($p['frecuencia'])) ?></div>
        <div class="col-6 col-md-4"><strong>Inicio:</strong> <?= fdate($p['fecha_inicio']) ?></div>
        <div class="col-6 col-md-4"><strong>Monto prestado:</strong> <?= money($p['monto']) ?></div>
        <div class="col-6 col-md-4"><strong>Capital pendiente:</strong> <?= money($p['capital_pendiente']) ?></div>
        <?php if ($p['tipo'] === 'san'): ?>
          <div class="col-6 col-md-4"><strong>Cuota:</strong> <?= money($p['monto_cuota']) ?> × <?= (int)$p['num_cuotas'] ?></div>
          <div class="col-6 col-md-4"><strong>Interés:</strong> <?= e($p['tasa']) ?>% (<?= (int)$p['meses_cobrados'] ?> meses) = <?= money($p['interes_total']) ?></div>
          <div class="col-6 col-md-4"><strong>Fin previsto:</strong> <?= fdate($p['fecha_fin']) ?></div>
        <?php else: ?>
          <div class="col-6 col-md-4"><strong>Rédito:</strong> <?= e($p['tasa']) ?>% por periodo</div>
        <?php endif; ?>
        <div class="col-6 col-md-4"><strong>Total cobrado:</strong> <?= money($cobrado) ?></div>
        <?php if (can('ganancias.ver')): ?>
        <div class="col-6 col-md-4"><strong>Ganancia generada:</strong> <span class="text-success"><?= money($ganancia) ?></span></div>
        <div class="col-6 col-md-4"><strong>Reinvertido:</strong> <span class="text-primary"><?= money($reinv) ?></span></div>
        <?php endif; ?>
        <?php if ($p['notas']): ?><div class="col-12"><strong>Notas:</strong> <?= e($p['notas']) ?></div><?php endif; ?>
      </div>
      <?php if ($p['estado'] === 'activo'): ?>
      <div class="row g-2 mt-2 text-center">
        <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Balance</div><div class="fw-bold"><?= money($r['balance']) ?></div></div></div>
        <div class="col-4"><div class="border rounded p-2 <?= $r['atrasadas'] ? 'border-danger text-danger' : '' ?>"><div class="small text-muted">Vencido</div><div class="fw-bold"><?= money($r['monto_atrasado']) ?></div><div class="small"><?= $r['atrasadas'] ? $r['atrasadas'] . ' · ' . $r['dias_atraso'] . ' días' : 'Al día' ?></div></div></div>
        <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Próximo pago</div><div class="fw-bold"><?= $r['proximo'] ? fdate($r['proximo']) : '—' ?></div><div class="small"><?= $r['proximo'] ? money($r['proximo_monto']) : '' ?></div></div></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-5">
    <?php if ($p['estado'] === 'activo' && can('pagos.registrar')): ?>
    <div class="card card-body h-100">
      <h3 class="h6">Registrar pago</h3>
      <form method="post" id="frmPago" data-confirm="¿Confirmar el registro de este pago?">
        <?= csrf_field() ?><input type="hidden" name="accion" value="pagar">
        <?php if ($p['tipo'] === 'redito'): ?>
          <div class="form-check"><input class="form-check-input" type="radio" name="modo" value="redito" id="m1" checked><label class="form-check-label" for="m1">Pagar sólo rédito (<?= money($opc['redito_periodo']) ?> por periodo)</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="modo" value="abono" id="m2"><label class="form-check-label" for="m2">Rédito al día + abono a capital</label></div>
          <div class="form-check mb-2"><input class="form-check-input" type="radio" name="modo" value="saldar" id="m3"><label class="form-check-label" for="m3">Saldar préstamo (<?= money($opc['saldar']) ?>)</label></div>
          <div class="small text-muted mb-2">Rédito pendiente hasta el periodo en curso: <strong><?= money($opc['redito_aldia']) ?></strong> (<?= $opc['periodos_aldia'] ?> periodo/s). Para abonar a capital se cobra primero este rédito.</div>
          <div class="row g-2">
            <div class="col-6 f-periodos"><label class="form-label small">Periodos a pagar</label><input type="number" name="periodos" min="1" value="<?= max(1, $opc['periodos_aldia']) ?>" class="form-control form-control-sm"></div>
            <div class="col-6 f-abono" style="display:none"><label class="form-label small">Abono a capital</label><input type="number" step="0.01" name="abono" min="0" max="<?= e($p['capital_pendiente']) ?>" class="form-control form-control-sm"></div>
        <?php else: ?>
          <div class="form-check"><input class="form-check-input" type="radio" name="modo" value="cuota" id="m1" checked><label class="form-check-label" for="m1">Pagar cuota(s) completas (<?= money($opc['cuota']) ?> c/u)</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="modo" value="redito_san" id="m2"><label class="form-check-label" for="m2">No completa: pagar rédito (<?= money($opc['redito']) ?>) — la cuota pasa al final</label></div>
          <div class="form-check mb-2"><input class="form-check-input" type="radio" name="modo" value="saldar" id="m3"><label class="form-check-label" for="m3">Saldar préstamo (<?= money($opc['saldar']) ?>, <?= $opc['pend'] ?> cuotas)</label></div>
          <div class="row g-2">
            <div class="col-6 f-periodos"><label class="form-label small">Cuotas a pagar</label><input type="number" name="periodos" min="1" max="<?= $opc['pend'] ?>" value="<?= max(1, $opc['vencidas']) ?>" class="form-control form-control-sm"></div>
        <?php endif; ?>
            <div class="col-6"><label class="form-label small">Fecha del pago</label><input type="date" name="fecha" value="<?= today() ?>" max="<?= today() ?>" min="<?= e($p['fecha_inicio']) ?>" class="form-control form-control-sm"></div>
            <div class="col-12"><label class="form-label small">Nota</label><input name="nota" class="form-control form-control-sm" maxlength="150"></div>
          </div>
        <button class="btn btn-success w-100 mt-3"><i class="bi bi-cash"></i> Registrar pago</button>
      </form>
    </div>
    <script>
      document.querySelectorAll('#frmPago input[name=modo]').forEach(r => r.addEventListener('change', () => {
        const m = document.querySelector('#frmPago input[name=modo]:checked').value;
        document.querySelectorAll('.f-periodos').forEach(e => e.style.display = (m === 'redito' || m === 'cuota') ? '' : 'none');
        document.querySelectorAll('.f-abono').forEach(e => e.style.display = m === 'abono' ? '' : 'none');
      }));
    </script>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header bg-white fw-semibold"><?= $p['tipo'] === 'san' ? 'Plan de cuotas' : 'Periodos de rédito' ?></div>
  <div class="table-responsive" style="max-height:420px">
  <table class="table table-sm mb-0">
    <thead class="sticky-top bg-white"><tr><th>#</th><th>Vence</th><th class="text-end">Monto</th><?php if ($p['tipo'] === 'san'): ?><th class="text-end">Capital</th><th class="text-end">Interés</th><?php endif; ?><th>Estado</th><th>Pagado el</th><th>Atraso</th></tr></thead>
    <tbody>
    <?php foreach ($cuotas as $c):
      $vencida = $c['estado'] === 'pendiente' && $c['fecha_vencimiento'] < date_add_days(today(), -$gracia); ?>
      <tr class="<?= $vencida ? 'table-danger' : ($c['estado'] === 'pospuesta' ? 'table-info' : '') ?>">
        <td><?= (int)$c['numero'] ?><?= $c['cuota_origen_id'] ? ' <i class="bi bi-arrow-return-right" title="Cuota movida al final"></i>' : '' ?></td>
        <td><?= fdate($c['fecha_vencimiento']) ?></td>
        <td class="text-end"><?= money($c['monto']) ?></td>
        <?php if ($p['tipo'] === 'san'): ?><td class="text-end"><?= money($c['capital']) ?></td><td class="text-end"><?= money($c['interes']) ?></td><?php endif; ?>
        <td><?= $vencida ? '<span class="badge bg-danger">Vencida</span>' : badge_estado($c['estado']) ?></td>
        <td><?= fdate($c['fecha_pago']) ?></td>
        <td><?= $vencida ? days_between($c['fecha_vencimiento'], today()) . ' d' : ((int)$c['dias_atraso'] ? $c['dias_atraso'] . ' d' : '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header bg-white fw-semibold">Pagos</div>
  <div class="table-responsive">
  <table class="table table-sm mb-0">
    <thead><tr><th>Recibo</th><th>Fecha</th><th>Detalle</th><th class="text-end">Capital</th><th class="text-end">Interés</th><th class="text-end">Rédito</th><th class="text-end">Total</th><th>Usuario</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pagos as $pg): ?>
      <tr class="<?= $pg['anulado'] ? 'text-decoration-line-through text-muted' : '' ?>">
        <td><a href="recibo.php?id=<?= $pg['id'] ?>"><?= e($pg['recibo']) ?></a></td>
        <td><?= fdate($pg['fecha']) ?></td>
        <td><?= e($pg['detalle']) ?><?= $pg['anulado'] ? '<div class="small">Anulado: ' . e($pg['motivo_anulacion']) . '</div>' : '' ?></td>
        <td class="text-end"><?= money($pg['capital']) ?></td>
        <td class="text-end"><?= money($pg['interes']) ?></td>
        <td class="text-end"><?= money($pg['redito']) ?></td>
        <td class="text-end fw-semibold"><?= money($pg['monto']) ?></td>
        <td><?= e($pg['usuario']) ?></td>
        <td>
          <?php if (can('prestamos.anular') && !$pg['anulado'] && (int)$pg['id'] === $ultimoPago): ?>
          <form method="post" class="d-flex gap-1" data-confirm="¿Anular este pago? Se revertirán sus efectos.">
            <?= csrf_field() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="pago_id" value="<?= $pg['id'] ?>">
            <input name="motivo" class="form-control form-control-sm" placeholder="Motivo" required style="width:120px">
            <button class="btn btn-sm btn-outline-danger">Anular</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; if (!$pagos): ?>
      <tr><td colspan="9" class="text-center text-muted py-3">Sin pagos registrados.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if (can('prestamos.anular')): ?>
<div class="card mt-3 no-print">
  <div class="card-header bg-white fw-semibold">Administración</div>
  <form method="post" class="card-body row g-2 align-items-end" data-confirm="¿Cambiar el estado del préstamo?">
    <?= csrf_field() ?><input type="hidden" name="accion" value="estado">
    <div class="col-md-3"><label class="form-label small">Nuevo estado</label>
      <select name="estado" class="form-select form-select-sm">
        <?php if ($p['estado'] !== 'activo'): ?><option value="activo">Reactivar</option><?php endif; ?>
        <option value="cancelado">Cancelado</option><option value="incobrable">Incobrable</option>
      </select></div>
    <div class="col-md-6"><label class="form-label small">Motivo</label><input name="motivo" class="form-control form-control-sm" required></div>
    <div class="col-md-3"><button class="btn btn-sm btn-outline-danger w-100">Aplicar</button></div>
  </form>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
