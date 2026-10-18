<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

use PDO;
use PDOStatement;

/** Dates are UTC strings calculated in PHP so the same SQL runs on MySQL and SQLite. */
final class DB
{
    public PDO $pdo;
    public string $driver;
    private int $txDepth = 0;

    /** @param array<string,mixed> $c */
    public function __construct(array $c)
    {
        $this->driver = (string)($c['driver'] ?? 'mysql');
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($this->driver === 'sqlite') {
            $path = (string)($c['path'] ?? (HC_ROOT . '/storage/helpcentre.sqlite'));
            $this->pdo = new PDO('sqlite:' . $path, null, null, $opts);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 8000');
            $this->pdo->exec('PRAGMA foreign_keys = OFF');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $c['host'] ?? 'localhost',
                (int)($c['port'] ?? 3306),
                $c['name'] ?? ''
            );
            if (!empty($c['socket'])) {
                $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $c['socket'], $c['name'] ?? '');
            }
            $this->pdo = new PDO($dsn, (string)($c['user'] ?? ''), (string)($c['pass'] ?? ''), $opts);
            $this->pdo->exec("SET time_zone = '+00:00'");
            $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }

    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    public function q(string $identifier): string
    {
        return $this->isSqlite() ? '"' . str_replace('"', '""', $identifier) . '"' : '`' . str_replace('`', '``', $identifier) . '`';
    }

    /** @param array<int|string,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        if (array_is_list($params)) {
            foreach ($params as $i => $v) {
                $st->bindValue($i + 1, $this->norm($v), $this->pdoType($v));
            }
        } else {
            foreach ($params as $k => $v) {
                $st->bindValue(str_starts_with((string)$k, ':') ? (string)$k : ':' . $k, $this->norm($v), $this->pdoType($v));
            }
        }
        $st->execute();
        return $st;
    }

    private function pdoType(mixed $v): int
    {
        return match (true) {
            is_int($v) => PDO::PARAM_INT,
            is_bool($v) => PDO::PARAM_INT,
            $v === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function row(string $sql, array $params = []): ?array
    {
        $r = $this->run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public function val(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return list<mixed> */
    public function col(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string,mixed>|null */
    public function find(string $table, int $id): ?array
    {
        return $this->row('SELECT * FROM ' . $this->q($table) . ' WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $cols = array_map(fn($c) => $this->q($c), array_keys($data));
        $sql = 'INSERT INTO ' . $this->q($table) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')';
        $this->run($sql, array_map([$this, 'norm'], array_values($data)));
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(string $table, array $data, string $where, array $params = []): int
    {
        if (!$data) {
            return 0;
        }
        $sets = [];
        foreach (array_keys($data) as $c) {
            $sets[] = $this->q($c) . ' = ?';
        }
        $sql = 'UPDATE ' . $this->q($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return $this->run($sql, array_merge(array_map([$this, 'norm'], array_values($data)), array_values($params)))->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->run('DELETE FROM ' . $this->q($table) . ' WHERE ' . $where, array_values($params))->rowCount();
    }

    private function norm(mixed $v): mixed
    {
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $v;
    }

    public static function in(array $values): string
    {
        return '(' . implode(',', array_fill(0, max(1, count($values)), '?')) . ')';
    }

    public function tx(callable $fn): mixed
    {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        }
        $this->txDepth++;
        try {
            $result = $fn($this);
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            $this->txDepth--;
            if ($this->txDepth === 0 && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function ilike(string $column): string
    {
        return $this->isSqlite() ? "$column LIKE ?" : "$column LIKE ?";
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
