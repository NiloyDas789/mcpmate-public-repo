<?php

use MCPMate\Config;
use MCPMate\Database\Connection as DB;
use MCPMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCPMate Pro's block editor queue (AGENTS.md, Block editor queue): block
 * changes an AI app queues for posts, which a site admin's browser applies
 * in the real block editor, so each block is saved by its own JavaScript
 * save(), and then saves as drafts.
 *
 * `block_batches` is one batch per row: its WordPress user and grant, a
 * status (open → ready → running → finalized | failed), the lease of the
 * browser finalizing it (SHA-256 of the token, never the token) and the
 * first error. `block_batch_items` holds one change per row: the post, the
 * version the AI read, its payload (markup, a JSON block tree, or nothing
 * for "re-save as it is"), the content the editor produced (`prepared`)
 * and the outcome. A batch the AI deletes gets `trashed_at`; Retention
 * deletes old batches with their items. dbDelta() is idempotent, so this
 * also runs on every DB_VERSION upgrade.
 */
final class MCPMateBlockBatches extends Migration
{
    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $batches = Config::withDBPrefix('block_batches');
        $items   = Config::withDBPrefix('block_batch_items');
        $charset = $wpdb->get_charset_collate();

        // dbDelta() format: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$batches} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  grant_id varchar(40) NOT NULL DEFAULT '',
  label varchar(200) NOT NULL DEFAULT '',
  note varchar(500) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'open',
  item_count int(10) unsigned NOT NULL DEFAULT 0,
  lease_hash char(64) NOT NULL DEFAULT '',
  lease_expires_at datetime DEFAULT NULL,
  error_json text DEFAULT NULL,
  requested_at datetime DEFAULT NULL,
  finalized_at datetime DEFAULT NULL,
  finalized_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  trashed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_trashed (user_id,trashed_at,updated_at),
  KEY status_updated (status,updated_at)
) {$charset};");

        dbDelta("CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id bigint(20) unsigned NOT NULL,
  position smallint(5) unsigned NOT NULL DEFAULT 0,
  post_id bigint(20) unsigned NOT NULL,
  mode varchar(10) NOT NULL,
  payload longtext NOT NULL,
  payload_bytes int(10) unsigned NOT NULL DEFAULT 0,
  expected_version char(16) NOT NULL,
  accept_loss varchar(16) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'queued',
  prepared longtext DEFAULT NULL,
  editor_json text DEFAULT NULL,
  result_json text DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY batch_position (batch_id,position),
  KEY post_id (post_id)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('block_batch_items') . '`');
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('block_batches') . '`');
    }
}
