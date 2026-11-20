<?php
declare(strict_types=1);

/* =====================================================================
 *  ÍNDICE DE CRÉDITO (escala 300 - 850, inspirado en modelos tipo Equifax/FICO)
 *
 *  Factor                         Peso   Fuente
 *  ---------------------------------------------------------------------
 *  Historial de pagos             35 %   historial_crediticio + cuotas vencidas
 *  Nivel de endeudamiento         30 %   saldo pendiente vs. monto de préstamos activos
 *  Antigüedad crediticia          15 %   meses desde el primer préstamo
 *  Préstamos completados          10 %   préstamos saldados vs. incobrables
 *  Crédito nuevo / simultáneo     10 %   cantidad de préstamos activos a la vez
 *
 *  Los eventos se ponderan por antigüedad: lo reciente pesa más.
 *  El administrador puede eliminar eventos viejos para que el cliente mejore.
 * ===================================================================== */

const CREDITO_MIN = 300;
const CREDITO_MAX = 850;

/** Valor (0..1) de un evento de pago según días de atraso. */
function credito_valor_evento(string $evento, int $dias): float
{
    if ($evento === 'redito_parcial') {
        return $dias > 0 ? max(0.2, 0.55 - $dias * 0.01) : 0.6;
    }
    if ($dias <= 0)  return 1.00;
    if ($dias <= 3)  return 0.85;
    if ($dias <= 7)  return 0.70;
    if ($dias <= 15) return 0.50;
    if ($dias <= 30) return 0.25;
    if ($dias <= 60) return 0.10;
    return 0.0;
}

/** Peso por antigüedad del evento. */
function credito_peso_recencia(string $fecha, string $hoy): float
{
    $meses = days_between($fecha, $hoy) / 30.4;
    if ($meses <= 6)  return 1.0;
    if ($meses <= 12) return 0.7;
    if ($meses <= 24) return 0.4;
    return 0.2;
}

/**
 * Calcula el índice y su desglose sin guardarlo.
 */
function credito_calcular(int $clienteId): array
{
    $hoy = today();
    $gracia = (int)setting('dias_gracia', 0);

    // ---- 1. Historial de pagos ----
    $eventos = q_all("SELECT evento, dias_atraso, fecha FROM historial_crediticio
                       WHERE cliente_id = ? AND evento IN ('pago_puntual','pago_tardio','redito_parcial')", [$clienteId]);
    $sumW = 0.0; $sumV = 0.0; $puntuales = 0; $tardios = 0; $parciales = 0;
    foreach ($eventos as $ev) {
        $w = credito_peso_recencia($ev['fecha'], $hoy);
        $sumW += $w;
        $sumV += $w * credito_valor_evento($ev['evento'], (int)$ev['dias_atraso']);
        if ($ev['evento'] === 'redito_parcial') $parciales++;
        elseif ((int)$ev['dias_atraso'] > 0) $tardios++;
        else $puntuales++;
    }
    // Cuotas actualmente vencidas sin pagar (no se pueden borrar: hay que pagarlas)
    $vencidas = q_all("SELECT cu.fecha_vencimiento FROM cuotas cu JOIN prestamos p ON p.id = cu.prestamo_id
                        WHERE p.cliente_id = ? AND p.estado = 'activo' AND cu.estado = 'pendiente'
                          AND cu.fecha_vencimiento < ?", [$clienteId, date_add_days($hoy, -$gracia)]);
    foreach ($vencidas as $v) {
        $dias = days_between($v['fecha_vencimiento'], $hoy);
        $sumW += 1.5;
        $sumV += 1.5 * credito_valor_evento('pago_tardio', $dias);
    }
    $factorPagos = $sumW > 0 ? $sumV / $sumW : 0.5;

    // ---- 2. Endeudamiento ----
    $activos = q_all("SELECT id, tipo, monto, capital_pendiente, interes_total FROM prestamos WHERE cliente_id = ? AND estado = 'activo'", [$clienteId]);
    if ($activos) {
        $deuda = 0.0; $original = 0.0;
        foreach ($activos as $a) {
            $original += (float)$a['monto'];
            $deuda += (float)$a['capital_pendiente'];
        }
        $ratio = $original > 0 ? min(1, $deuda / $original) : 0;
        $factorDeuda = 1 - 0.6 * $ratio; // 100% adeudado = 0.4 ; casi saldado = 1.0
    } else {
        $factorDeuda = 1.0;
    }

    // ---- 3. Antigüedad ----
    $primero = q_val('SELECT MIN(fecha_inicio) FROM prestamos WHERE cliente_id = ?', [$clienteId]);
    $mesesHist = $primero ? max(0, days_between($primero, $hoy) / 30.4) : 0;
    $factorAntig = min(1, $mesesHist / 24);

    // ---- 4. Préstamos completados ----
    $saldados = (int)q_val("SELECT COUNT(*) FROM historial_crediticio WHERE cliente_id = ? AND evento = 'prestamo_saldado'", [$clienteId]);
    $incob = (int)q_val("SELECT COUNT(*) FROM historial_crediticio WHERE cliente_id = ? AND evento = 'prestamo_incobrable'", [$clienteId]);
    $factorComp = max(0, min(1, $saldados / 5) - 0.5 * $incob);

    // ---- 5. Crédito simultáneo ----
    $n = count($activos);
    $factorNuevo = $n <= 1 ? 1.0 : ($n === 2 ? 0.6 : 0.3);

    $score = CREDITO_MIN + (CREDITO_MAX - CREDITO_MIN) * (
        0.35 * $factorPagos + 0.30 * $factorDeuda + 0.15 * $factorAntig + 0.10 * $factorComp + 0.10 * $factorNuevo
    );
    // Un préstamo incobrable registrado limita el máximo alcanzable.
    if ($incob > 0) $score = min($score, 579);
    $score = (int)round(max(CREDITO_MIN, min(CREDITO_MAX, $score)));

    return [
        'score'   => $score,
        'factores' => [
            'pagos'       => ['peso' => 35, 'valor' => round($factorPagos * 100), 'detalle' => "$puntuales puntuales, $tardios tardíos, $parciales con rédito parcial, " . count($vencidas) . ' vencidas sin pagar'],
            'deuda'       => ['peso' => 30, 'valor' => round($factorDeuda * 100), 'detalle' => $activos ? 'Préstamos activos: ' . count($activos) : 'Sin deuda activa'],
            'antiguedad'  => ['peso' => 15, 'valor' => round($factorAntig * 100), 'detalle' => round($mesesHist, 1) . ' meses de historial'],
            'completados' => ['peso' => 10, 'valor' => round($factorComp * 100), 'detalle' => "$saldados saldados, $incob incobrables"],
            'simultaneo'  => ['peso' => 10, 'valor' => round($factorNuevo * 100), 'detalle' => "$n préstamo(s) activo(s)"],
        ],
    ];
}

function credito_recalcular(int $clienteId): int
{
    $r = credito_calcular($clienteId);
    q('UPDATE clientes SET indice_credito = ?, indice_actualizado = NOW() WHERE id = ?', [$r['score'], $clienteId]);
    return $r['score'];
}

/** Categoría del índice: [etiqueta, color bootstrap]. */
function credito_categoria(?int $score): array
{
    if ($score === null) return ['Sin historial', 'secondary'];
    if ($score >= 800) return ['Excelente', 'success'];
    if ($score >= 740) return ['Muy bueno', 'success'];
    if ($score >= 670) return ['Bueno', 'primary'];
    if ($score >= 580) return ['Regular', 'warning'];
    return ['Deficiente', 'danger'];
}

function credito_badge($score): string
{
    $s = $score === null ? null : (int)$score;
    [$label, $color] = credito_categoria($s);
    return '<span class="badge bg-' . $color . '">' . ($s ?? '—') . ' · ' . e($label) . '</span>';
}

/** Recalcula el índice de todos los clientes (las cuotas vencidas cambian con el tiempo). */
function credito_recalcular_todos(): int
{
    $n = 0;
    foreach (q_all('SELECT id FROM clientes') as $c) {
        credito_recalcular((int)$c['id']);
        $n++;
    }
    setting_save('credito_ultimo_recalculo', today());
    return $n;
}
