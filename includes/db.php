<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        global $CONFIG;
        $c = $CONFIG['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ] + pdo_ssl_options($c));
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

/** Ejecuta una consulta preparada y devuelve el statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function q_val(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

/** Inserta un registro y devuelve el id. */
function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = sprintf(
        'INSERT INTO `%s` (`%s`) VALUES (%s)',
        $table,
        implode('`,`', $cols),
        implode(',', array_fill(0, count($cols), '?'))
    );
    q($sql, array_values($data));
    return (int)db()->lastInsertId();
}

/** Actualiza registros por id. */
function db_update(string $table, array $data, int $id): void
{
    $sets = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($data)));
    $params = array_values($data);
    $params[] = $id;
    q("UPDATE `$table` SET $sets WHERE id = ?", $params);
}

/** Ejecuta una función dentro de una transacción. */
function db_tx(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn($pdo);
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
