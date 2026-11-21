<?php

use MCPMate\Config;
use MCPMate\Database\Connection as DB;
use MCPMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCPMate Pro's Chat in wp-admin (docs/pro-parity.md P22): one row per
 * conversation, per WordPress user (`messages_json` is the Messages API
 * history, images replaced by their row in `chat_images`), and the images
 * themselves, kept apart so no single row passes a 4 MB MySQL packet.
 * `status` idle|tools|running is claimed with one conditional UPDATE that
 * also sets `claim_token`, so two tabs never run the same step twice and
 * a step whose claim was taken over can no longer save; `revision` counts
 * saves (an UPDATE that matched always changes a row). dbDelta() is idempotent, so this
 * also runs on every DB_VERSION upgrade.
 */
final class MCPMateChatSessions extends Migration
{
    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sessions = Config::withDBPrefix('chat_sessions');
        $images   = Config::withDBPrefix('chat_images');
        $charset  = $wpdb->get_charset_collate();

        // dbDelta() format: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$sessions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  title varchar(200) NOT NULL DEFAULT '',
  model varchar(64) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'idle',
  claim_token varchar(32) NOT NULL DEFAULT '',
  revision int(10) unsigned NOT NULL DEFAULT 0,
  messages_json longtext NOT NULL,
  meta_json longtext NOT NULL,
  bytes int(10) unsigned NOT NULL DEFAULT 0,
  image_bytes int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_updated (user_id,updated_at),
  KEY updated_at (updated_at)
) {$charset};");

        dbDelta("CREATE TABLE {$images} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  media_type varchar(20) NOT NULL,
  bytes int(10) unsigned NOT NULL,
  data longblob NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY session_id (session_id),
  KEY user_id (user_id)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('chat_images') . '`');
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('chat_sessions') . '`');
    }
}
