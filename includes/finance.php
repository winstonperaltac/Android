<?php
declare(strict_types=1);

/*
 * Libro de movimientos financieros.
 *   ganancia            (+) rédito completo y 50% del interés de cuotas
 *   reinversion         (+) 50% del interés de cuotas que vuelve al capital de trabajo
 *   capital_recuperado  (+) capital prestado que regresa
 *   desembolso          (-) dinero prestado
 *   gasto               (-) gastos operativos (se pagan de las ganancias)
 *   sueldo              (-) nómina (se paga de las ganancias)
 *   aporte              (+) capital aportado por el dueño
 *   retiro              (-) retiro de ganancias por el dueño
 *   reinversion_manual  (+) ganancias trasladadas manualmente al capital
 */
const TIPOS_MOVIMIENTO = [
    'ganancia' => 'Ganancia', 'reinversion' => 'Interés reinvertido', 'capital_recuperado' => 'Capital recuperado',
    'desembolso' => 'Desembolso', 'gasto' => 'Gasto operativo', 'sueldo' => 'Sueldo', 'aporte' => 'Aporte de capital',
    'retiro' => 'Retiro de ganancias', 'reinversion_manual' => 'Ganancia reinvertida',
];

function movimiento_registrar(string $tipo, ?string $origen, float $monto, string $desc, ?string $fecha = null, array $refs = []): int
{
    return db_insert('movimientos', [
        'fecha'       => $fecha ?? today(),
        'tipo'        => $tipo,
        'origen'      => $origen,
        'monto'       => round($monto, 2),
        'descripcion' => $desc,
        'prestamo_id' => $refs['prestamo_id'] ?? null,
        'pago_id'     => $refs['pago_id'] ?? null,
        'gasto_id'    => $refs['gasto_id'] ?? null,
        'nomina_id'   => $refs['nomina_id'] ?? null,
        'usuario_id'  => current_user()['id'] ?? null,
    ]);
}

/** Totales por tipo de movimiento en un rango (o histórico). */
function finanzas_totales(?string $desde = null, ?string $hasta = null): array
{
    $where = '1=1'; $p = [];
    if ($desde) { $where .= ' AND fecha >= ?'; $p[] = $desde; }
    if ($hasta) { $where .= ' AND fecha <= ?'; $p[] = $hasta; }
    $t = array_fill_keys(array_keys(TIPOS_MOVIMIENTO), 0.0);
    foreach (q_all("SELECT tipo, SUM(monto) s FROM movimientos WHERE $where GROUP BY tipo", $p) as $r) {
        $t[$r['tipo']] = (float)$r['s'];
    }
    // Ganancia neta disponible = ganancias - gastos - sueldos - retiros - reinversión manual
    $t['ganancia_neta'] = round($t['ganancia'] + $t['gasto'] + $t['sueldo'] + $t['retiro'] - $t['reinversion_manual'], 2);
    // Capital de trabajo = aportes + reinversión + capital recuperado - desembolsos + reinversión manual
    $t['capital_caja'] = round($t['aporte'] + $t['reinversion'] + $t['reinversion_manual'] + $t['capital_recuperado'] + $t['desembolso'], 2);
    return $t;
}

/** Rango de fechas para un periodo: semana, quincena, mes, año. */
function periodo_rango(string $periodo, ?string $ref = null): array
{
    $d = new DateTime($ref ?? today());
    switch ($periodo) {
        case 'semana':
            $ini = (clone $d)->modify('monday this week');
            $fin = (clone $ini)->modify('+6 days');
            break;
        case 'quincena':
            $day = (int)$d->format('j');
            if ($day <= 15) {
                $ini = new DateTime($d->format('Y-m-01'));
                $fin = new DateTime($d->format('Y-m-15'));
            } else {
                $ini = new DateTime($d->format('Y-m-16'));
                $fin = new DateTime($d->format('Y-m-t'));
            }
            break;
        case 'anio':
            $ini = new DateTime($d->format('Y-01-01'));
            $fin = new DateTime($d->format('Y-12-31'));
            break;
        case 'mes':
        default:
            $ini = new DateTime($d->format('Y-m-01'));
            $fin = new DateTime($d->format('Y-m-t'));
    }
    return [$ini->format('Y-m-d'), $fin->format('Y-m-d')];
}
