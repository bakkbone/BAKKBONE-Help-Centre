<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

/** Additive only: nothing is ever dropped. */
final class Schema
{
    /** @return array<string,array<string,mixed>> */
    public static function definition(): array
    {
        return require HC_ROOT . '/database/schema.php';
    }

    /** @return list<string> */
    public static function sync(DB $db, ?array $definition = null): array
    {
        $log = [];
        foreach ($definition ?? self::definition() as $table => $def) {
            if (!self::tableExists($db, $table)) {
                $db->pdo->exec(self::createTableSql($db, $table, $def));
                $log[] = "Created table $table";
            } else {
                $existing = self::columns($db, $table);
                foreach ($def['columns'] as $col => $spec) {
                    if (!in_array(strtolower($col), $existing, true)) {
                        $db->pdo->exec('ALTER TABLE ' . $db->q($table) . ' ADD COLUMN ' . self::columnSql($db, $col, $spec, true));
                        $log[] = "Added column $table.$col";
                    }
                }
            }
            foreach (['unique' => true, 'index' => false] as $kind => $unique) {
                foreach ($def[$kind] ?? [] as $cols) {
                    $name = ($unique ? 'uq_' : 'ix_') . $table . '_' . implode('_', $cols);
                    if (!self::indexExists($db, $table, $name)) {
                        $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $db->q($name) . ' ON ' . $db->q($table)
                            . ' (' . implode(',', array_map(fn($c) => $db->q($c), $cols)) . ')';
                        $db->pdo->exec($sql);
                        $log[] = "Created index $name";
                    }
                }
            }
        }
        return $log;
    }

    private static function createTableSql(DB $db, string $table, array $def): string
    {
        $lines = [];
        foreach ($def['columns'] as $col => $spec) {
            $lines[] = '  ' . self::columnSql($db, $col, $spec);
        }
        if (!empty($def['primary'])) {
            $lines[] = '  PRIMARY KEY (' . implode(',', array_map(fn($c) => $db->q($c), $def['primary'])) . ')';
        }
        $sql = 'CREATE TABLE ' . $db->q($table) . " (\n" . implode(",\n", $lines) . "\n)";
        if (!$db->isSqlite()) {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        return $sql;
    }

    private static function columnSql(DB $db, string $col, string $spec, bool $alter = false): string
    {
        $hasDefault = str_contains($spec, '|');
        [$typeSpec, $default] = $hasDefault ? explode('|', $spec, 2) : [$spec, null];
        [$type, $len] = array_pad(explode(':', $typeSpec, 2), 2, null);
        $sqlite = $db->isSqlite();

        if ($type === 'pk') {
            return $db->q($col) . ($sqlite ? ' INTEGER PRIMARY KEY AUTOINCREMENT' : ' INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
        }
        $sqlType = match ($type) {
            'int' => $sqlite ? 'INTEGER' : 'INT',
            'bigint' => $sqlite ? 'INTEGER' : 'BIGINT',
            'bool' => $sqlite ? 'INTEGER' : 'TINYINT(1)',
            'string' => 'VARCHAR(' . (int)($len ?: 255) . ')',
            'text' => 'TEXT',
            'longtext' => $sqlite ? 'TEXT' : 'MEDIUMTEXT',
            'datetime' => $sqlite ? 'TEXT' : 'DATETIME',
            default => throw new \InvalidArgumentException("Unknown column type $type"),
        };
        $out = $db->q($col) . ' ' . $sqlType;
        $isText = in_array($type, ['text', 'longtext'], true);
        if ($hasDefault && !$isText) {
            $out .= ' NOT NULL DEFAULT ' . (in_array($type, ['int', 'bigint', 'bool'], true) ? (string)(int)$default : $db->pdo->quote((string)$default));
        } else {
            $out .= ' NULL';
        }
        return $out;
    }

    public static function tableExists(DB $db, string $table): bool
    {
        if ($db->isSqlite()) {
            return (bool)$db->val("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
        }
        return (bool)$db->val('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    public static function hasColumn(DB $db, string $table, string $column): bool
    {
        static $cache = [];
        $cache[$table] ??= self::columns($db, $table);
        return in_array(strtolower($column), $cache[$table], true);
    }

    /** @return list<string> */
    private static function columns(DB $db, string $table): array
    {
        if ($db->isSqlite()) {
            return array_map(fn($r) => strtolower($r['name']), $db->all('PRAGMA table_info(' . $db->q($table) . ')'));
        }
        return array_map('strtolower', $db->col('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]));
    }

    private static function indexExists(DB $db, string $table, string $name): bool
    {
        if ($db->isSqlite()) {
            return (bool)$db->val("SELECT name FROM sqlite_master WHERE type='index' AND name = ?", [$name]);
        }
        return (bool)$db->val('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1', [$table, $name]);
    }
}
