<?php
/**
 * Run this ONCE to add the social-login link table.
 * Visit https://members.zachalcasid.com/migrate-oauth.php in your browser,
 * then DELETE this file immediately (like install.php).
 *
 * (Or paste the CREATE TABLE below into phpMyAdmin and skip this file.)
 */
require_once __DIR__ . '/includes/db.php';

db()->exec("
CREATE TABLE IF NOT EXISTS oauth_accounts (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  user_id          INT NOT NULL,
  provider         VARCHAR(20)  NOT NULL,
  provider_user_id VARCHAR(191) NOT NULL,
  email            VARCHAR(190) DEFAULT '',
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_provider_user (provider, provider_user_id),
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

echo '<pre>';
echo "✅ oauth_accounts table is ready.\n\n";
echo "🗑️  DELETE THIS FILE (migrate-oauth.php) NOW.\n";
echo '</pre>';
