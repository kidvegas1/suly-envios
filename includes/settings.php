<?php

function app_setting(string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $value = $row ? (string)$row['setting_value'] : $default;
    } catch (\Throwable $e) {
        $value = $default;
    }

    $cache[$key] = $value;
    return $value;
}

function app_setting_float(string $key, float $default = 0.0): float {
    $value = app_setting($key, (string)$default);
    return is_numeric($value) ? (float)$value : $default;
}

function app_setting_set(string $key, string $value): void {
    $pdo = db();
    $stmt = $pdo->prepare(sql_upsert('app_settings', ['setting_key', 'setting_value'], ['setting_value'], ['setting_key']));
    $stmt->execute([$key, $value]);
}
