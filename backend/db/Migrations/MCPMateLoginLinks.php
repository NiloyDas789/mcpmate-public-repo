<?php

use MCPMate\Config;
use MCPMate\Database\Connection as DB;
use MCPMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCPMate Pro's one-time admin login links (create_admin_login_link, an
 * opt-in hatch: security rule 9). `id` is the link's 16-hex public part;
 * the secret is only ever stored as its SHA-256 (`token_hash`). A link is
 * `pending` until a site admin approves it, then `approved` for 5 minutes
 * (`expires_at`), then `used` once (one conditional UPDATE). `ip` is the
 * address that asked for it; only that address may open it. Retention
 * deletes rows a day after they expired. dbDelta() is idempotent, so this
 * also runs on every DB_VERSION upgrade.
 */
final class MCPMateLoginLinks extends Migration
{
    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = Config::withDBPrefix('login_links');
        $charset = $wpdb->get_charset_collate();

        // dbDelta() format: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$table} (
  id char(16) NOT NULL,
  token_hash char(64) NOT NULL,
  grant_id varchar(40) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  ip varchar(45) NOT NULL,
  status varchar(20) NOT NULL,
  approval_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  approved_at datetime DEFAULT NULL,
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY grant_status (grant_id,status),
  KEY expires_at (expires_at)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('login_links') . '`');
    }
}
