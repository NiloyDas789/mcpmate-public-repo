<?php

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The append-only audit log of every write the MCP surface performs, and
 * of signed reads while "Also log read calls" is on (`kind`, 0.6.0).
 *
 * dbDelta() is idempotent, so this runs on activation and again on every
 * DB_VERSION upgrade; column changes go here and dbDelta() applies the diff.
 */
final class McpMateAuditLog extends Migration
{
    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = Config::withDBPrefix('audit_log');
        $charset = $wpdb->get_charset_collate();

        // dbDelta() format: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  request_id varchar(36) NOT NULL DEFAULT '',
  tool varchar(100) NOT NULL DEFAULT '',
  action varchar(100) NOT NULL DEFAULT '',
  target varchar(191) NOT NULL DEFAULT '',
  args_json longtext NULL,
  result varchar(20) NOT NULL,
  message text NULL,
  grant_id varchar(40) NOT NULL DEFAULT '',
  auth varchar(12) NOT NULL DEFAULT '',
  kind varchar(10) NOT NULL DEFAULT 'write',
  mcp_tool varchar(60) NOT NULL DEFAULT '',
  duration_ms int(10) unsigned NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY tool_created_at (tool,created_at),
  KEY result_created_at (result,created_at),
  KEY request_id (request_id),
  KEY grant_id (grant_id),
  KEY created_at_kind (created_at,kind)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('audit_log') . '`');
    }
}
