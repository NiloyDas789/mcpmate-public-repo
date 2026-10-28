<?php

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Change history (0.6.0): one row per object a write made through the
 * plugin changed, with JSON snapshots before and after (ChangeStore).
 *
 * dbDelta() is idempotent, so this also runs on every DB_VERSION upgrade.
 */
final class McpMateChanges extends Migration
{
    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = Config::withDBPrefix('changes');
        $charset = $wpdb->get_charset_collate();

        // dbDelta() format: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  audit_id bigint(20) unsigned NOT NULL DEFAULT 0,
  request_id varchar(36) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  grant_id varchar(40) NOT NULL DEFAULT '',
  tool varchar(100) NOT NULL DEFAULT '',
  mcp_tool varchar(60) NOT NULL DEFAULT '',
  action varchar(10) NOT NULL,
  object_type varchar(20) NOT NULL,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_key varchar(191) NOT NULL DEFAULT '',
  object_label varchar(255) NOT NULL DEFAULT '',
  before_json longtext NULL,
  after_json longtext NULL,
  truncated tinyint(1) NOT NULL DEFAULT 0,
  bytes int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY request_id (request_id),
  KEY audit_id (audit_id),
  KEY object (object_type,object_id),
  KEY grant_id (grant_id)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('changes') . '`');
    }
}
