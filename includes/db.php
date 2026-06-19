<?php
/**
 * Database Connection & Helpers
 */
require_once __DIR__ . '/../config.php';

function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    try {
        if (DB_TYPE === 'sqlite') {
            $dbDir = dirname(DB_SQLITE_PATH);
            if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
            $pdo = new PDO('sqlite:' . DB_SQLITE_PATH);
        } else {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS
            );
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        if (DB_TYPE === 'sqlite') {
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA foreign_keys=ON');
        }
    } catch (PDOException $e) {
        die("Erro de conexão: " . $e->getMessage());
    }
    return $pdo;
}

function query($sql, $params = []) {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function fetchAll($sql, $params = []) {
    return query($sql, $params)->fetchAll();
}

function fetchOne($sql, $params = []) {
    return query($sql, $params)->fetch();
}

function insert($table, $data) {
    $cols = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
    query($sql, array_values($data));
    return getDB()->lastInsertId();
}

function update($table, $data, $where, $whereParams = []) {
    $sets = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
    $sql = "UPDATE {$table} SET {$sets} WHERE {$where}";
    query($sql, array_merge(array_values($data), $whereParams));
}

function delete($table, $where, $params = []) {
    query("DELETE FROM {$table} WHERE {$where}", $params);
}

function getSetting($key, $default = '') {
    $row = fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    return $row ? $row['setting_value'] : $default;
}

function setSetting($key, $value) {
    $existing = fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
    if ($existing) {
        query("UPDATE settings SET setting_value = ?, updated_at = datetime('now') WHERE setting_key = ?", [$value, $key]);
    } else {
        insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
    }
}

function upsert($table, $data, $uniqueCols) {
    $whereClause = implode(' AND ', array_map(fn($c) => "{$c} = ?", $uniqueCols));
    $whereParams = array_map(fn($c) => $data[$c], $uniqueCols);
    $existing = fetchOne("SELECT id FROM {$table} WHERE {$whereClause}", $whereParams);
    if ($existing) {
        $updateData = array_diff_key($data, array_flip($uniqueCols));
        if (!empty($updateData)) {
            update($table, $updateData, $whereClause, $whereParams);
        }
        return $existing['id'];
    } else {
        return insert($table, $data);
    }
}

function logSync($source, $level, $step, $message, $details = '', $httpCode = null, $durationMs = null) {
    try {
        insert('sync_logs', [
            'timestamp' => date('Y-m-d H:i:s'),
            'source' => $source,
            'level' => $level,
            'step' => $step,
            'message' => $message,
            'details' => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
        ]);
    } catch (Exception $e) {
        // Silently fail — don't break sync over logging
    }
}
