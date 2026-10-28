<?php

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Destructive operations waiting for (or decided by) a site admin.
 *
 * Only the SHA-256 of each approval token is stored; the token itself is
 * returned to the caller once. `grant_id` is the MCP connection that asked
 * (check_approval looks requests up by it). dbDelta() is idempotent, so this also runs
 * on every DB_VERSION upgrade.
 */
final class McpMateApprovals extends Migration
{
    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = Config::withDBPrefix('approvals');
        $charset = $wpdb->get_charset_collate();

        // dbDelta() format: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  grant_id varchar(40) NOT NULL DEFAULT '',
  request_id varchar(36) NOT NULL DEFAULT '',
  tool varchar(100) NOT NULL,
  action varchar(100) NOT NULL DEFAULT '',
  target varchar(191) NOT NULL DEFAULT '',
  args_json longtext NOT NULL,
  preview_json longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  expires_at datetime NOT NULL,
  decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
  decided_at datetime NULL,
  executed_at datetime NULL,
  result_json longtext NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY status_expires_at (status,expires_at),
  KEY created_at (created_at),
  KEY grant_id (grant_id)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('approvals') . '`');
    }
}
