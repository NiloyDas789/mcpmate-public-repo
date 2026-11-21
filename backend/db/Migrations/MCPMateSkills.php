<?php

use MCPMate\Config;
use MCPMate\Database\Connection as DB;
use MCPMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Skills WordPress users saved with save_skill (docs/mcp-in-plugin.md §6.2,
 * §8): one row per user, scope and name. MCPMate's own playbooks are files
 * in skills/, not rows. `trashed_at` (DB 0.12.0) is set by MCPMate Pro's
 * delete_skill or a site admin's Move to trash; a trashed skill is hidden
 * from AI apps until restored. dbDelta() is idempotent, so this also runs
 * on every DB_VERSION upgrade (and adds the column to an existing table).
 */
final class MCPMateSkills extends Migration
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
  trashed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_scope_name (wp_user_id,this_site_only,name),
  KEY wp_user_id (wp_user_id),
  KEY trashed_at (trashed_at)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('skills') . '`');
    }
}
