<?php
declare(strict_types=1);

/* =====================================================================
 *  LÓGICA DE PRÉSTAMOS
 *
 *  RÉDITO: sin cuotas. Cada periodo (semana/quincena) genera un rédito
 *          igual a tasa% del capital pendiente. El cliente puede:
 *            - pagar sólo el rédito (100% ganancia)
 *            - pagar el rédito del periodo actual + abonar a capital
 *            - saldar: capital + rédito del periodo actual
 *
 *  SAN:    cuotas fijas semanales o quincenales. Interés = 10% mensual,
 *          mínimo 30% (si dura menos de 3 meses). Una fracción de mes
 *          (semanas adicionales) se cobra como mes completo.
 *          Si el cliente no completa la cuota paga "rédito" = 50% de la
 *          cuota (100% ganancia) y la cuota se mueve al final del plan.
 *          El interés de cada cuota se divide: 50% ganancia / 50% capital.
 * ===================================================================== */

/** Periodos que forman un mes según la frecuencia. */
function periodos_por_mes(string $frecuencia): float
{
    return $frecuencia === 'semanal' ? (float)setting('semanas_por_mes', 4) : 2.0;
}

/**
 * Calcula un préstamo San.
 */
function san_calcular(float $monto, string $frecuencia, int $numCuotas): array
{
    if ($monto <= 0 || $numCuotas <= 0) {
        throw new InvalidArgumentException('Monto y número de cuotas deben ser mayores que cero.');
    }
    $ppm         = periodos_por_mes($frecuencia);
    $tasaMensual = (float)setting('san_tasa_mensual', 10);
    $minimo      = (float)setting('san_interes_minimo', 30);
    $mesesMin    = (int)setting('san_meses_minimo', 3);

    $mesesExactos = $numCuotas / $ppm;
    // Cualquier fracción de mes (semanas extra) se cobra como mes completo.
    $meses = (int)ceil(round($mesesExactos, 6));
    $tasa  = $meses * $tasaMensual;
    if ($meses < $mesesMin || $tasa < $minimo) {
        $tasa = max($tasa, $minimo);
    }

    $interes = round($monto * $tasa / 100, 2);
    $total   = round($monto + $interes, 2);
    $cuota   = round($total / $numCuotas, 2);

    // Desglose por cuota; la última absorbe el redondeo.
    $capCuota = round($monto / $numCuotas, 2);
    $intCuota = round($interes / $numCuotas, 2);
    $plan = [];
    $accCap = 0; $accInt = 0;
    for ($i = 1; $i <= $numCuotas; $i++) {
        if ($i < $numCuotas) {
            $c = $capCuota; $n = $intCuota;
        } else {
            $c = round($monto - $accCap, 2);
            $n = round($interes - $accInt, 2);
        }
        $accCap += $c; $accInt += $n;
        $plan[] = ['numero' => $i, 'capital' => $c, 'interes' => $n, 'monto' => round($c + $n, 2)];
    }

    return [
        'monto'          => $monto,
        'frecuencia'     => $frecuencia,
        'num_cuotas'     => $numCuotas,
        'meses_exactos'  => round($mesesExactos, 2),
        'meses_cobrados' => $meses,
        'tasa'           => $tasa,
        'interes_total'  => $interes,
        'total'          => $total,
        'monto_cuota'    => $cuota,
        'plan'           => $plan,
    ];
}

function prestamo_get(int $id): ?array
{
    return q_one(
        'SELECT p.*, c.nombres, c.apellidos, c.cedula, c.celular, c.foto, c.indice_credito
           FROM prestamos p JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ?',
        [$id]
    );
}

/**
 * Crea un préstamo y su plan de pagos. Devuelve el id.
 */
function prestamo_crear(array $d): int
{
    $userId = current_user()['id'] ?? null;
    $tipo = $d['tipo'];
    $frec = $d['frecuencia'];
    $monto = round((float)$d['monto'], 2);
    $inicio = $d['fecha_inicio'];
    $dias = frecuencia_dias($frec);

    return db_tx(function () use ($d, $tipo, $frec, $monto, $inicio, $dias, $userId) {
        $row = [
            'codigo'            => 'TMP' . bin2hex(random_bytes(6)),
            'cliente_id'        => (int)$d['cliente_id'],
            'tipo'              => $tipo,
            'frecuencia'        => $frec,
            'monto'             => $monto,
            'capital_pendiente' => $monto,
            'fecha_inicio'      => $inicio,
            'notas'             => $d['notas'] ?? null,
            'creado_por'        => $userId,
        ];
        if ($tipo === 'san') {
            $calc = san_calcular($monto, $frec, (int)$d['num_cuotas']);
            $row += [
                'tasa'           => $calc['tasa'],
                'num_cuotas'     => $calc['num_cuotas'],
                'meses_cobrados' => $calc['meses_cobrados'],
                'interes_total'  => $calc['interes_total'],
                'monto_cuota'    => $calc['monto_cuota'],
                'fecha_fin'      => date_add_days($inicio, $dias * $calc['num_cuotas']),
            ];
        } else {
            $row['tasa'] = round((float)($d['tasa'] ?? setting('redito_tasa', 10)), 2);
        }
        $id = db_insert('prestamos', $row);
        $codigo = sprintf('P-%06d', $id);
        q('UPDATE prestamos SET codigo = ? WHERE id = ?', [$codigo, $id]);

        if ($tipo === 'san') {
            foreach ($calc['plan'] as $c) {
                db_insert('cuotas', [
                    'prestamo_id'       => $id,
                    'numero'            => $c['numero'],
                    'fecha_vencimiento' => date_add_days($inicio, $dias * $c['numero']),
                    'monto'             => $c['monto'],
                    'capital'           => $c['capital'],
                    'interes'           => $c['interes'],
                ]);
            }
        }

        movimiento_registrar('desembolso', 'prestamo', -$monto, "Desembolso préstamo $codigo", $inicio, ['prestamo_id' => $id]);
        audit('crear', 'prestamos', (string)$id, "Préstamo $codigo ($tipo, $frec) por " . money($monto), null, $row);

        if ($tipo === 'redito') {
            redito_sincronizar($id);
        }
        credito_recalcular((int)$d['cliente_id']);
        return $id;
    });
}

/**
 * Préstamo rédito: genera los periodos vencidos hasta cubrir la fecha indicada.
 * Siempre existe al menos el periodo en curso.
 */
function redito_sincronizar(int $prestamoId, ?string $hasta = null): void
{
    $p = q_one('SELECT * FROM prestamos WHERE id = ?', [$prestamoId]);
    if (!$p || $p['tipo'] !== 'redito' || $p['estado'] !== 'activo') return;
    $hasta = $hasta ?? today();
    $dias = frecuencia_dias($p['frecuencia']);
    $ultimo = q_one('SELECT numero, fecha_vencimiento FROM cuotas WHERE prestamo_id = ? ORDER BY numero DESC LIMIT 1', [$prestamoId]);
    $num = $ultimo ? (int)$ultimo['numero'] : 0;
    $venc = $ultimo ? $ultimo['fecha_vencimiento'] : $p['fecha_inicio'];
    $redito = round((float)$p['capital_pendiente'] * (float)$p['tasa'] / 100, 2);
    $guard = 0;
    while (($num === 0 || $venc < $hasta) && $guard++ < 1000) {
        $num++;
        $venc = date_add_days($p['fecha_inicio'], $dias * $num);
        db_insert('cuotas', [
            'prestamo_id' => $prestamoId, 'numero' => $num, 'fecha_vencimiento' => $venc,
            'monto' => $redito, 'interes' => $redito,
        ]);
    }
}

/** Periodo de rédito en curso (el primero cuyo vencimiento es hoy o posterior). */
function redito_periodo_actual(int $prestamoId, ?string $fecha = null): ?array
{
    $fecha = $fecha ?? today();
    redito_sincronizar($prestamoId, $fecha);
    return q_one('SELECT * FROM cuotas WHERE prestamo_id = ? AND fecha_vencimiento >= ? ORDER BY numero LIMIT 1', [$prestamoId, $fecha]);
}

/** Periodos de rédito pendientes hasta el periodo en curso inclusive. */
function redito_pendientes_hasta_actual(int $prestamoId, ?string $fecha = null): array
{
    $actual = redito_periodo_actual($prestamoId, $fecha);
    if (!$actual) return [];
    return q_all("SELECT * FROM cuotas WHERE prestamo_id = ? AND estado = 'pendiente' AND numero <= ? ORDER BY numero", [$prestamoId, $actual['numero']]);
}

/** Días de atraso de una cuota pagada en $fechaPago, considerando días de gracia. */
function dias_atraso(string $vencimiento, string $fechaPago): int
{
    $gracia = (int)setting('dias_gracia', 0);
    return max(0, days_between($vencimiento, $fechaPago) - $gracia);
}

/** Marca una cuota como pagada y guarda el estado previo para posibles anulaciones. */
function cuota_marcar_pagada(array $cuota, string $fecha, array &$apl): int
{
    $apl['cuotas'][] = [
        'id' => (int)$cuota['id'], 'estado' => $cuota['estado'], 'pagado' => $cuota['pagado'],
        'fecha_pago' => $cuota['fecha_pago'], 'dias_atraso' => $cuota['dias_atraso'],
    ];
    $atraso = dias_atraso($cuota['fecha_vencimiento'], $fecha);
    q("UPDATE cuotas SET estado = 'pagada', pagado = monto, fecha_pago = ?, dias_atraso = ? WHERE id = ?", [$fecha, $atraso, $cuota['id']]);
    return $atraso;
}

/** Recalcula el monto de rédito de los periodos futuros no pagados tras un cambio de capital. */
function redito_actualizar_futuros(int $prestamoId): void
{
    $p = q_one('SELECT capital_pendiente, tasa FROM prestamos WHERE id = ?', [$prestamoId]);
    $r = round((float)$p['capital_pendiente'] * (float)$p['tasa'] / 100, 2);
    q("UPDATE cuotas SET monto = ?, interes = ? WHERE prestamo_id = ? AND estado = 'pendiente' AND pagado = 0", [$r, $r, $prestamoId]);
}

/**
 * Registra un pago. Opciones según tipo de préstamo:
 *   rédito: modo = 'redito' (n periodos) | 'abono' (rédito al día + abono) | 'saldar'
 *   san:    modo = 'cuota' (n cuotas) | 'redito_san' (50% de la cuota) | 'saldar'
 * Devuelve el id del pago.
 */
function pago_registrar(int $prestamoId, string $modo, string $fecha, int $periodos = 1, float $abono = 0.0, string $nota = ''): int
{
    return db_tx(function () use ($prestamoId, $modo, $fecha, $periodos, $abono, $nota) {
        $p = q_one('SELECT * FROM prestamos WHERE id = ? FOR UPDATE', [$prestamoId]);
        if (!$p) throw new RuntimeException('Préstamo no encontrado.');
        if ($p['estado'] !== 'activo') throw new RuntimeException('El préstamo no está activo.');
        if ($fecha < $p['fecha_inicio']) throw new RuntimeException('La fecha del pago no puede ser anterior al inicio del préstamo.');
        if ($fecha > today()) throw new RuntimeException('La fecha del pago no puede ser futura.');
        $periodos = max(1, $periodos);

        $apl = ['cuotas' => [], 'creadas' => [], 'eliminadas' => [], 'capital_antes' => $p['capital_pendiente'], 'estado_antes' => $p['estado'], 'fecha_fin_antes' => $p['fecha_fin']];
        $capital = 0.0; $interes = 0.0; $redito = 0.0;
        $eventos = []; // historial crediticio
        $detalle = '';
        $cerrar = false;

        if ($p['tipo'] === 'redito') {
            redito_sincronizar($prestamoId, today());
            $capPend = (float)$p['capital_pendiente'];

            if ($modo === 'redito') {
                // Si faltan periodos futuros, se generan.
                $pend = q_all("SELECT * FROM cuotas WHERE prestamo_id = ? AND estado = 'pendiente' ORDER BY numero", [$prestamoId]);
                while (count($pend) < $periodos) {
                    $ult = q_one('SELECT fecha_vencimiento FROM cuotas WHERE prestamo_id = ? ORDER BY numero DESC LIMIT 1', [$prestamoId]);
                    redito_sincronizar($prestamoId, date_add_days($ult['fecha_vencimiento'], 1));
                    $pend = q_all("SELECT * FROM cuotas WHERE prestamo_id = ? AND estado = 'pendiente' ORDER BY numero", [$prestamoId]);
                }
                foreach (array_slice($pend, 0, $periodos) as $c) {
                    $at = cuota_marcar_pagada($c, $fecha, $apl);
                    $redito += (float)$c['monto'];
                    $eventos[] = ['cuota' => $c, 'atraso' => $at, 'evento' => $at > 0 ? 'pago_tardio' : 'pago_puntual'];
                }
                $detalle = "Rédito de $periodos periodo(s)";
            } elseif ($modo === 'abono' || $modo === 'saldar') {
                // Regla: para abonar a capital debe estar pagado el rédito del periodo en curso.
                foreach (redito_pendientes_hasta_actual($prestamoId, $fecha) as $c) {
                    $at = cuota_marcar_pagada($c, $fecha, $apl);
                    $redito += (float)$c['monto'];
                    $eventos[] = ['cuota' => $c, 'atraso' => $at, 'evento' => $at > 0 ? 'pago_tardio' : 'pago_puntual'];
                }
                $capital = $modo === 'saldar' ? $capPend : round($abono, 2);
                if ($capital <= 0) throw new RuntimeException('Indique el monto a abonar a capital.');
                if ($capital > $capPend + 0.001) throw new RuntimeException('El abono excede el capital pendiente (' . money($capPend) . ').');
                $nuevoCap = round($capPend - $capital, 2);
                q('UPDATE prestamos SET capital_pendiente = ? WHERE id = ?', [$nuevoCap, $prestamoId]);
                if ($nuevoCap <= 0) {
                    $cerrar = true;
                } else {
                    redito_actualizar_futuros($prestamoId);
                }
                $detalle = $modo === 'saldar' ? 'Saldo total (capital + rédito)' : 'Rédito al día + abono a capital ' . money($capital);
            } else {
                throw new RuntimeException('Modo de pago inválido para préstamo a rédito.');
            }
        } else { // SAN
            $pend = q_all("SELECT * FROM cuotas WHERE prestamo_id = ? AND estado = 'pendiente' ORDER BY fecha_vencimiento, numero", [$prestamoId]);
            if (!$pend) throw new RuntimeException('El préstamo no tiene cuotas pendientes.');

            if ($modo === 'cuota' || $modo === 'saldar') {
                $lista = $modo === 'saldar' ? $pend : array_slice($pend, 0, $periodos);
                foreach ($lista as $c) {
                    $at = cuota_marcar_pagada($c, $fecha, $apl);
                    $capital += (float)$c['capital'];
                    $interes += (float)$c['interes'];
                    $eventos[] = ['cuota' => $c, 'atraso' => $at, 'evento' => $at > 0 ? 'pago_tardio' : 'pago_puntual'];
                }
                $detalle = $modo === 'saldar' ? 'Saldo total del préstamo (' . count($lista) . ' cuotas)' : 'Pago de ' . count($lista) . ' cuota(s)';
            } elseif ($modo === 'redito_san') {
                // El cliente no pudo pagar la cuota completa: paga la mitad como rédito y la cuota pasa al final.
                $c = $pend[0];
                $redito = round((float)$c['monto'] / 2, 2);
                $apl['cuotas'][] = ['id' => (int)$c['id'], 'estado' => $c['estado'], 'pagado' => $c['pagado'], 'fecha_pago' => $c['fecha_pago'], 'dias_atraso' => $c['dias_atraso']];
                $at = dias_atraso($c['fecha_vencimiento'], $fecha);
                q("UPDATE cuotas SET estado = 'pospuesta', fecha_pago = ?, dias_atraso = ? WHERE id = ?", [$fecha, $at, $c['id']]);
                $ult = q_one('SELECT MAX(numero) n, MAX(fecha_vencimiento) f FROM cuotas WHERE prestamo_id = ?', [$prestamoId]);
                $nuevaVenc = date_add_days($ult['f'], frecuencia_dias($p['frecuencia']));
                $nid = db_insert('cuotas', [
                    'prestamo_id' => $prestamoId, 'numero' => (int)$ult['n'] + 1, 'fecha_vencimiento' => $nuevaVenc,
                    'monto' => $c['monto'], 'capital' => $c['capital'], 'interes' => $c['interes'], 'cuota_origen_id' => $c['id'],
                ]);
                $apl['creadas'][] = $nid;
                q('UPDATE prestamos SET fecha_fin = ? WHERE id = ?', [$nuevaVenc, $prestamoId]);
                $eventos[] = ['cuota' => $c, 'atraso' => $at, 'evento' => 'redito_parcial'];
                $detalle = "Rédito de cuota #{$c['numero']} (cuota movida al final, vence " . fdate($nuevaVenc) . ')';
            } else {
                throw new RuntimeException('Modo de pago inválido para préstamo san.');
            }
            if ($capital > 0) {
                q('UPDATE prestamos SET capital_pendiente = GREATEST(0, ROUND(capital_pendiente - ?, 2)) WHERE id = ?', [$capital, $prestamoId]);
            }
            $restantes = (int)q_val("SELECT COUNT(*) FROM cuotas WHERE prestamo_id = ? AND estado = 'pendiente'", [$prestamoId]);
            if ($restantes === 0) $cerrar = true;
        }

        if ($cerrar) {
            // Elimina periodos de rédito futuros no pagados y cierra el préstamo.
            foreach (q_all("SELECT * FROM cuotas WHERE prestamo_id = ? AND estado = 'pendiente'", [$prestamoId]) as $c) {
                $apl['eliminadas'][] = $c;
            }
            q("DELETE FROM cuotas WHERE prestamo_id = ? AND estado = 'pendiente'", [$prestamoId]);
            q("UPDATE prestamos SET estado = 'saldado', capital_pendiente = 0, cerrado_en = NOW() WHERE id = ?", [$prestamoId]);
        }

        $total = round($capital + $interes + $redito, 2);
        if ($total <= 0) throw new RuntimeException('El pago no tiene monto.');

        $pagoId = db_insert('pagos', [
            'recibo'      => 'TMP' . bin2hex(random_bytes(6)),
            'prestamo_id' => $prestamoId,
            'fecha'       => $fecha,
            'monto'       => $total,
            'capital'     => round($capital, 2),
            'interes'     => round($interes, 2),
            'redito'      => round($redito, 2),
            'tipo'        => $modo,
            'detalle'     => trim($detalle . ($nota !== '' ? ' — ' . $nota : '')),
            'aplicacion'  => json_encode($apl),
            'usuario_id'  => current_user()['id'] ?? null,
        ]);
        $recibo = sprintf('R-%07d', $pagoId);
        q('UPDATE pagos SET recibo = ? WHERE id = ?', [$recibo, $pagoId]);

        // ----- Libro financiero -----
        $ref = ['prestamo_id' => $prestamoId, 'pago_id' => $pagoId];
        if ($capital > 0) {
            movimiento_registrar('capital_recuperado', 'pago', $capital, "Capital recuperado {$p['codigo']} ($recibo)", $fecha, $ref);
        }
        if ($interes > 0) {
            $pctReinv = (float)setting('porcentaje_reinversion', 50);
            $reinv = round($interes * $pctReinv / 100, 2);
            $gan = round($interes - $reinv, 2);
            if ($gan > 0) movimiento_registrar('ganancia', 'interes', $gan, "Interés de cuota {$p['codigo']} ($recibo)", $fecha, $ref);
            if ($reinv > 0) movimiento_registrar('reinversion', 'interes', $reinv, "Interés reinvertido {$p['codigo']} ($recibo)", $fecha, $ref);
        }
        if ($redito > 0) {
            movimiento_registrar('ganancia', 'redito', $redito, "Rédito {$p['codigo']} ($recibo)", $fecha, $ref);
        }

        // ----- Historial crediticio -----
        foreach ($eventos as $ev) {
            $c = $ev['cuota'];
            db_insert('historial_crediticio', [
                'cliente_id'  => $p['cliente_id'],
                'prestamo_id' => $prestamoId,
                'cuota_id'    => $c['id'],
                'pago_id'     => $pagoId,
                'fecha'       => $fecha,
                'evento'      => $ev['evento'],
                'dias_atraso' => $ev['atraso'],
                'monto'       => $c['monto'],
                'descripcion' => "{$p['codigo']} " . ($p['tipo'] === 'san' ? 'cuota' : 'periodo') . " #{$c['numero']} vence " . fdate($c['fecha_vencimiento']),
            ]);
        }
        if ($cerrar) {
            db_insert('historial_crediticio', [
                'cliente_id' => $p['cliente_id'], 'prestamo_id' => $prestamoId, 'pago_id' => $pagoId,
                'fecha' => $fecha, 'evento' => 'prestamo_saldado', 'monto' => $p['monto'],
                'descripcion' => "Préstamo {$p['codigo']} saldado",
            ]);
        }

        audit('pago', 'pagos', (string)$pagoId, "Pago $recibo a {$p['codigo']}: " . money($total) . " ($detalle)", null, [
            'prestamo' => $p['codigo'], 'modo' => $modo, 'fecha' => $fecha, 'monto' => $total,
            'capital' => $capital, 'interes' => $interes, 'redito' => $redito,
        ]);
        credito_recalcular((int)$p['cliente_id']);
        return $pagoId;
    });
}

/**
 * Anula un pago (sólo administrador). Debe ser el último pago vigente del préstamo
 * para garantizar que el estado se pueda restaurar íntegramente.
 */
function pago_anular(int $pagoId, string $motivo): void
{
    db_tx(function () use ($pagoId, $motivo) {
        $pg = q_one('SELECT * FROM pagos WHERE id = ? FOR UPDATE', [$pagoId]);
        if (!$pg || $pg['anulado']) throw new RuntimeException('Pago no encontrado o ya anulado.');
        $ultimo = (int)q_val('SELECT MAX(id) FROM pagos WHERE prestamo_id = ? AND anulado = 0', [$pg['prestamo_id']]);
        if ($ultimo !== (int)$pg['id']) throw new RuntimeException('Sólo puede anularse el último pago vigente del préstamo.');
        $apl = json_decode((string)$pg['aplicacion'], true) ?: [];
        $p = q_one('SELECT * FROM prestamos WHERE id = ?', [$pg['prestamo_id']]);

        foreach ($apl['eliminadas'] ?? [] as $c) {
            unset($c['id']);
            db_insert('cuotas', $c);
        }
        foreach ($apl['creadas'] ?? [] as $cid) {
            q('DELETE FROM cuotas WHERE id = ?', [$cid]);
        }
        foreach ($apl['cuotas'] ?? [] as $c) {
            q('UPDATE cuotas SET estado = ?, pagado = ?, fecha_pago = ?, dias_atraso = ? WHERE id = ?', [$c['estado'], $c['pagado'], $c['fecha_pago'], $c['dias_atraso'], $c['id']]);
        }
        q('UPDATE prestamos SET capital_pendiente = ?, estado = ?, fecha_fin = ?, cerrado_en = IF(? = \'activo\', NULL, cerrado_en) WHERE id = ?', [
            $apl['capital_antes'] ?? $p['capital_pendiente'], $apl['estado_antes'] ?? 'activo', $apl['fecha_fin_antes'] ?? $p['fecha_fin'], $apl['estado_antes'] ?? 'activo', $p['id'],
        ]);
        if ($p['tipo'] === 'redito') redito_actualizar_futuros((int)$p['id']);

        q('DELETE FROM movimientos WHERE pago_id = ?', [$pagoId]);
        q('DELETE FROM historial_crediticio WHERE pago_id = ?', [$pagoId]);
        q('UPDATE pagos SET anulado = 1, anulado_por = ?, anulado_en = NOW(), motivo_anulacion = ? WHERE id = ?', [current_user()['id'] ?? null, $motivo, $pagoId]);
        audit('anular', 'pagos', (string)$pagoId, "Anulación del pago {$pg['recibo']} ({$p['codigo']}): $motivo", $pg, null);
        credito_recalcular((int)$p['cliente_id']);
    });
}

/**
 * Resumen de estado de un préstamo: balance, atrasos, próximo vencimiento.
 */
function prestamo_resumen(array $p, ?string $fecha = null): array
{
    $fecha = $fecha ?? today();
    $gracia = (int)setting('dias_gracia', 0);
    $limite = date_add_days($fecha, -$gracia);
    $r = ['balance' => 0.0, 'atrasadas' => 0, 'monto_atrasado' => 0.0, 'dias_atraso' => 0, 'proximo' => null, 'proximo_monto' => 0.0, 'pagadas' => 0, 'total_cuotas' => 0];
    if ($p['tipo'] === 'redito' && $p['estado'] === 'activo') {
        redito_sincronizar((int)$p['id'], $fecha);
    }
    $cuotas = q_all('SELECT * FROM cuotas WHERE prestamo_id = ? ORDER BY fecha_vencimiento, numero', [$p['id']]);
    foreach ($cuotas as $c) {
        if ($c['estado'] === 'pospuesta') continue;
        $r['total_cuotas']++;
        if ($c['estado'] === 'pagada') { $r['pagadas']++; continue; }
        if ($p['tipo'] === 'san') $r['balance'] += (float)$c['monto'] - (float)$c['pagado'];
        if ($c['fecha_vencimiento'] < $limite) {
            $r['atrasadas']++;
            $r['monto_atrasado'] += (float)$c['monto'] - (float)$c['pagado'];
            $r['dias_atraso'] = max($r['dias_atraso'], days_between($c['fecha_vencimiento'], $fecha));
            if ($p['tipo'] === 'redito') $r['balance'] += (float)$c['monto'];
        } elseif ($r['proximo'] === null) {
            $r['proximo'] = $c['fecha_vencimiento'];
            $r['proximo_monto'] = (float)$c['monto'] - (float)$c['pagado'];
        }
    }
    if ($p['tipo'] === 'redito') {
        $r['balance'] += (float)$p['capital_pendiente'];
    }
    $r['balance'] = round($r['balance'], 2);
    $r['monto_atrasado'] = round($r['monto_atrasado'], 2);
    return $r;
}

/** Cambia el estado de un préstamo (cancelado / incobrable). Sólo administrador. */
function prestamo_cambiar_estado(int $id, string $estado, string $motivo): void
{
    if (!in_array($estado, ['cancelado', 'incobrable', 'activo'], true)) throw new InvalidArgumentException('Estado inválido');
    $p = q_one('SELECT * FROM prestamos WHERE id = ?', [$id]);
    q('UPDATE prestamos SET estado = ?, cerrado_en = ' . ($estado === 'activo' ? 'NULL' : 'NOW()') . ' WHERE id = ?', [$estado, $id]);
    if ($estado === 'incobrable') {
        db_insert('historial_crediticio', [
            'cliente_id' => $p['cliente_id'], 'prestamo_id' => $id, 'fecha' => today(),
            'evento' => 'prestamo_incobrable', 'monto' => $p['capital_pendiente'],
            'descripcion' => "Préstamo {$p['codigo']} declarado incobrable: $motivo",
        ]);
    }
    audit('cambiar_estado', 'prestamos', (string)$id, "Préstamo {$p['codigo']}: {$p['estado']} → $estado. Motivo: $motivo", ['estado' => $p['estado']], ['estado' => $estado]);
    credito_recalcular((int)$p['cliente_id']);
}
