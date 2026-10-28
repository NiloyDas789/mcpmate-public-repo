<?php

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Grants: one approved AI client each. `client_key` names the OAuth client
 * of the grant (McpMateOAuth).
 *
 * dbDelta() is idempotent, so this also runs on every DB_VERSION upgrade.
 */
final class McpMateConnection extends Migration
{
    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $grants  = Config::withDBPrefix('grants');
        $charset = $wpdb->get_charset_collate();

        // dbDelta() format: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$grants} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  grant_id varchar(40) NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'pending',
  wp_user_id bigint(20) unsigned NOT NULL,
  client_id varchar(2048) NOT NULL DEFAULT '',
  client_key char(64) NULL,
  client_name varchar(100) NOT NULL DEFAULT '',
  vouched_by varchar(255) NOT NULL DEFAULT '',
  redirect_host varchar(255) NOT NULL DEFAULT '',
  scopes varchar(100) NOT NULL DEFAULT '',
  tools text NULL,
  created_at datetime NOT NULL,
  last_used_at datetime NULL,
  first_call_at datetime NULL,
  revoked_at datetime NULL,
  revoked_by bigint(20) unsigned NULL,
  revoke_reason varchar(20) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY grant_id (grant_id),
  KEY wp_user_id (wp_user_id),
  KEY status (status),
  KEY client_key (client_key)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('grants') . '`');
    }
}
