<?php

use MCPMate\Config;
use MCPMate\Database\Connection as DB;
use MCPMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCPMate Pro's upload links (create_upload_link): a single-use,
 * 10-minute address an AI app sends one local file to, bound to one grant,
 * a file name, a size cap, a type and where the file goes. `id` is the
 * link's 16-hex public part (in the URL); the secret is only ever stored as
 * its SHA-256 (`token_hash`). `used_at` is set by one conditional UPDATE,
 * so a link uploads at most once. Retention deletes rows a day after they
 * expire. dbDelta() is idempotent, so this also runs on every DB_VERSION
 * upgrade.
 */
final class MCPMateUploadLinks extends Migration
{
    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = Config::withDBPrefix('upload_links');
        $charset = $wpdb->get_charset_collate();

        // dbDelta() format: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$table} (
  id char(16) NOT NULL,
  token_hash char(64) NOT NULL,
  grant_id varchar(40) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  filename varchar(200) NOT NULL,
  bytes int(10) unsigned NOT NULL,
  mime varchar(100) NOT NULL,
  fields_json text DEFAULT NULL,
  placement_json text DEFAULT NULL,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY grant_unused (grant_id,used_at,expires_at),
  KEY expires_at (expires_at)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('upload_links') . '`');
    }
}
