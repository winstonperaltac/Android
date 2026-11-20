<?php
// Devuelve en JSON el cálculo de un préstamo san (vista previa en el formulario).
require __DIR__ . '/includes/bootstrap.php';
require_perm('prestamos.crear');
header('Content-Type: application/json; charset=utf-8');
try {
    $frec = input('frecuencia') === 'quincenal' ? 'quincenal' : 'semanal';
    $calc = san_calcular(input_money('monto'), $frec, input_int('num_cuotas'));
    unset($calc['plan']);
    echo json_encode(['ok' => true] + $calc);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
