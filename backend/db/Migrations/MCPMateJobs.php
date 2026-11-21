<?php

use MCPMate\Config;
use MCPMate\Database\Connection as DB;
use MCPMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCPMate Pro's background WP-CLI jobs (CliJobs): a command run that may
 * take longer than a request (search-replace, plugin update and install,
 * cache flush, cron event run) is stored here and run once by WP-Cron (or
 * Action Scheduler), as `run_as`, and get_cli_job reads it back for
 * `requested_by`. `id` is 16 random hex characters. `status` moves
 * queued → running → done | failed, each step one conditional UPDATE, so a
 * job never runs twice. `args_json` holds the parsed command (args, flags,
 * the flags the caller wrote), `output_json` the progress lines,
 * `result_json` the command's answer. Retention deletes jobs 30 days after
 * they finished. dbDelta() is idempotent, so this also runs on every
 * DB_VERSION upgrade.
 */
final class MCPMateJobs extends Migration
{
    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = Config::withDBPrefix('jobs');
        $charset = $wpdb->get_charset_collate();

        // dbDelta() format: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$table} (
  id char(16) NOT NULL,
  command varchar(100) NOT NULL,
  args_json longtext NOT NULL,
  target varchar(191) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL,
  approved tinyint(1) unsigned NOT NULL DEFAULT 0,
  run_as bigint(20) unsigned NOT NULL,
  requested_by bigint(20) unsigned NOT NULL,
  grant_id varchar(40) NOT NULL DEFAULT '',
  request_id varchar(36) NOT NULL DEFAULT '',
  progress_done int(10) unsigned NOT NULL DEFAULT 0,
  progress_total int(10) unsigned NOT NULL DEFAULT 0,
  progress_message varchar(200) NOT NULL DEFAULT '',
  output_json longtext DEFAULT NULL,
  result_json longtext DEFAULT NULL,
  error_json text DEFAULT NULL,
  created_at datetime NOT NULL,
  started_at datetime DEFAULT NULL,
  heartbeat_at datetime DEFAULT NULL,
  finished_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY requester_status (requested_by,status),
  KEY status_created (status,created_at)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('jobs') . '`');
    }
}
