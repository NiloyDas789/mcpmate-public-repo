<?php

use MCPMate\Config;
use MCPMate\Database\Connection as DB;
use MCPMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCPMate Pro's agent memory (docs/pro-parity.md P2): notes an AI app keeps
 * for one WordPress user with save_memory, shared by that user's grants.
 * `grant_id` is the grant that created the note (empty when it is gone or
 * never was). `tags` is stored as ",a,b," so one LIKE finds a tag on
 * MySQL 5.5. A note the AI deletes gets `trashed_at`; only a site admin
 * removes rows (or Retention, once the trash is old). dbDelta() is
 * idempotent, so this also runs on every DB_VERSION upgrade.
 */
final class MCPMateMemories extends Migration
{
    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = Config::withDBPrefix('memories');
        $charset = $wpdb->get_charset_collate();

        // dbDelta() format: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  grant_id varchar(40) NOT NULL DEFAULT '',
  title varchar(200) NOT NULL,
  content mediumtext NOT NULL,
  tags varchar(500) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  trashed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_trashed (user_id,trashed_at,updated_at),
  KEY trashed_at (trashed_at)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('memories') . '`');
    }
}
