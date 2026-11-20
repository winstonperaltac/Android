<?php
require __DIR__ . '/includes/bootstrap.php';
require_perm('configuracion');

$campos = [
    'empresa_nombre' => 'Nombre de la empresa', 'empresa_rnc' => 'RNC / Identificación fiscal',
    'empresa_telefono' => 'Teléfono', 'empresa_direccion' => 'Dirección', 'moneda' => 'Símbolo de moneda',
    'redito_tasa' => 'Rédito por periodo (%)', 'san_tasa_mensual' => 'Interés mensual san (%)',
    'san_interes_minimo' => 'Interés mínimo san (%)', 'san_meses_minimo' => 'Meses bajo los cuales aplica el mínimo',
    'semanas_por_mes' => 'Semanas que forman un mes', 'porcentaje_reinversion' => '% del interés de cuotas que se reinvierte',
    'dias_gracia' => 'Días de gracia antes de considerar atraso',
];
$numericos = ['redito_tasa', 'san_tasa_mensual', 'san_interes_minimo', 'san_meses_minimo', 'semanas_por_mes', 'porcentaje_reinversion', 'dias_gracia'];

if (is_post()) {
    csrf_check();
    try {
        if (input('accion') === 'recalcular') {
            $n = credito_recalcular_todos();
            audit('recalcular', 'credito', null, "Recálculo manual del índice de crédito de $n clientes");
            flash('success', "Índice recalculado para $n clientes.");
            redirect('configuracion.php');
        }
        $antes = settings_all();
        $nuevos = [];
        foreach ($campos as $k => $l) {
            $v = (string)input($k, '');
            if (in_array($k, $numericos, true)) {
                if (!is_numeric($v) || (float)$v < 0) throw new RuntimeException("Valor inválido en: $l");
                if ($k === 'porcentaje_reinversion' && (float)$v > 100) throw new RuntimeException('El porcentaje de reinversión no puede exceder 100.');
                if ($k === 'semanas_por_mes' && (float)$v <= 0) throw new RuntimeException('Las semanas por mes deben ser mayores que cero.');
            }
            $nuevos[$k] = mb_substr($v, 0, 255);
        }
        $logo = upload_image('logo', 'logo', 2097152, true, 400);
        if ($logo) {
            image_delete(setting('empresa_logo'));
            $nuevos['empresa_logo'] = $logo;
        } elseif (isset($_POST['quitar_logo'])) {
            image_delete(setting('empresa_logo'));
            $nuevos['empresa_logo'] = '';
        }
        foreach ($nuevos as $k => $v) setting_save($k, $v);
        [$a, $d] = audit_diff($antes, $nuevos);
        if ($d) audit('editar', 'configuracion', null, 'Cambio de configuración: ' . implode(', ', array_keys($d)), $a, $d);
        flash('success', 'Configuración guardada.');
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('configuracion.php');
}

$page_title = 'Configuración';
require __DIR__ . '/includes/header.php';
?>
<form method="post" enctype="multipart/form-data" class="card card-body mb-3">
  <?= csrf_field() ?>
  <h3 class="h6">Empresa (marca blanca)</h3>
  <div class="row g-3 mb-3">
    <?php foreach (['empresa_nombre', 'empresa_rnc', 'empresa_telefono', 'moneda'] as $k): ?>
      <div class="col-md-3"><label class="form-label small"><?= e($campos[$k]) ?></label><input name="<?= $k ?>" class="form-control form-control-sm" value="<?= e(setting($k, '')) ?>"></div>
    <?php endforeach; ?>
    <div class="col-md-6"><label class="form-label small"><?= e($campos['empresa_direccion']) ?></label><input name="empresa_direccion" class="form-control form-control-sm" value="<?= e(setting('empresa_direccion', '')) ?>"></div>
    <div class="col-md-6"><label class="form-label small">Logo (máx. 2 MB)</label>
      <div class="d-flex gap-2 align-items-center">
        <?php if (setting('empresa_logo')): ?><img src="<?= e(setting('empresa_logo')) ?>" style="max-height:48px" alt="Logo"><?php endif; ?>
        <input type="file" name="logo" accept="image/*" class="form-control form-control-sm">
      </div>
      <?php if (setting('empresa_logo')): ?><div class="form-check"><input type="checkbox" name="quitar_logo" id="ql" class="form-check-input"><label class="form-check-label small" for="ql">Quitar logo</label></div><?php endif; ?>
    </div>
  </div>
  <h3 class="h6">Reglas de préstamos</h3>
  <div class="row g-3 mb-3">
    <?php foreach ($numericos as $k): ?>
      <div class="col-md-3"><label class="form-label small"><?= e($campos[$k]) ?></label><input type="number" step="0.01" min="0" name="<?= $k ?>" class="form-control form-control-sm" value="<?= e(setting($k, '0')) ?>"></div>
    <?php endforeach; ?>
  </div>
  <div class="small text-muted mb-3">Los cambios de tasas sólo afectan a los préstamos nuevos. Los existentes conservan las condiciones con las que se crearon.</div>
  <div><button class="btn btn-primary">Guardar configuración</button></div>
</form>
<form method="post" class="card card-body" data-confirm="¿Recalcular el índice de crédito de todos los clientes?">
  <?= csrf_field() ?><input type="hidden" name="accion" value="recalcular">
  <h3 class="h6">Índice de crédito</h3>
  <p class="small text-muted mb-2">El índice se recalcula automáticamente con cada pago y una vez al día. Último recálculo general: <?= fdate(setting('credito_ultimo_recalculo')) ?: 'nunca' ?>.</p>
  <div><button class="btn btn-outline-primary btn-sm">Recalcular ahora</button></div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
