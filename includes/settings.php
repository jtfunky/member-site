<?php
// Generic site-wide admin-toggleable settings (app_settings table, migrate-app-settings.sql).
// Wrapped in try/catch like other newer tables (raffle.php, my-plays.php) so an
// un-migrated table degrades to the given default instead of a fatal error.

function getSetting(string $key, string $default = ''): string {
    try {
        $st = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $st->execute([$key]);
        $val = $st->fetchColumn();
        return $val === false ? $default : $val;
    } catch (\Throwable $e) {
        return $default;
    }
}

function setSetting(string $key, string $value): void {
    try {
        db()->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, $value]);
    } catch (\Throwable $e) {
        // Table not migrated yet — silently no-op, same convention as getSetting().
    }
}

function isLeaderboardEnabled(): bool {
    return getSetting('leaderboard_enabled', '0') === '1';
}
