<?php

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Skills WordPress users saved with save_skill (docs/mcp-in-plugin.md §6.2,
 * §8): one row per user, scope and name. McpMate's own playbooks are files
 * in skills/, not rows. dbDelta() is idempotent, so this also runs on every
 * DB_VERSION upgrade.
 */
final class McpMateSkills extends Migration
{
    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = Config::withDBPrefix('skills');
        $charset = $wpdb->get_charset_collate();

        // dbDelta() format: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  wp_user_id bigint(20) unsigned NOT NULL,
  this_site_only tinyint(1) NOT NULL DEFAULT 0,
  name varchar(64) NOT NULL,
  description varchar(200) NOT NULL,
  body_markdown mediumtext NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_scope_name (wp_user_id,this_site_only,name),
  KEY wp_user_id (wp_user_id)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('skills') . '`');
    }
}
