<?php

use McpMate\Config;
use McpMate\Database\Connection as DB;
use McpMate\Migration\Migration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The plugin's own OAuth server (docs/mcp-in-plugin.md §4, §6.2): clients
 * (registered or fetched metadata documents), authorization requests waiting
 * for a site admin, and codes, access and refresh tokens. Every secret value
 * is stored only as its SHA-256.
 *
 * 0.10.0: clients gain `revoked_at` (a site admin revoked them).
 *
 * The grants table's `client_key` column is added by McpMateConnection.
 * dbDelta() is idempotent, so this also runs on every DB_VERSION upgrade.
 */
final class McpMateOAuth extends Migration
{
    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $clients  = Config::withDBPrefix('oauth_clients');
        $requests = Config::withDBPrefix('oauth_requests');
        $tokens   = Config::withDBPrefix('oauth_tokens');
        $charset  = $wpdb->get_charset_collate();

        // dbDelta() format: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$clients} (
  client_key char(64) NOT NULL,
  client_id text NOT NULL,
  kind varchar(4) NOT NULL,
  client_name varchar(100) NOT NULL DEFAULT '',
  client_uri varchar(2048) NULL,
  redirect_uris text NOT NULL,
  auth_method varchar(20) NOT NULL DEFAULT 'none',
  secret_hash char(64) NULL,
  metadata_json text NOT NULL,
  fetched_at datetime NULL,
  cache_until datetime NULL,
  created_at datetime NOT NULL,
  last_used_at datetime NULL,
  revoked_at datetime NULL,
  PRIMARY KEY  (client_key),
  KEY created_at (created_at)
) {$charset};");

        dbDelta("CREATE TABLE {$requests} (
  request_hash char(64) NOT NULL,
  client_key char(64) NOT NULL,
  redirect_uri varchar(2048) NOT NULL,
  state varchar(512) NOT NULL DEFAULT '',
  code_challenge char(43) NOT NULL,
  scopes varchar(100) NOT NULL DEFAULT '',
  resource varchar(2048) NOT NULL,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime NULL,
  PRIMARY KEY  (request_hash),
  KEY expires_at (expires_at)
) {$charset};");

        dbDelta("CREATE TABLE {$tokens} (
  token_hash char(64) NOT NULL,
  kind varchar(8) NOT NULL,
  grant_id varchar(40) NOT NULL,
  client_key char(64) NOT NULL,
  family_id char(22) NULL,
  parent_hash char(64) NULL,
  scopes varchar(100) NOT NULL DEFAULT '',
  resource varchar(2048) NOT NULL,
  code_challenge char(43) NULL,
  redirect_uri varchar(2048) NULL,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime NULL,
  revoked_at datetime NULL,
  PRIMARY KEY  (token_hash),
  KEY grant_id (grant_id),
  KEY family_id (family_id),
  KEY expires_at (expires_at)
) {$charset};");
    }

    public function down(): void
    {
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('oauth_tokens') . '`');
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('oauth_requests') . '`');
        DB::query('DROP TABLE IF EXISTS `' . Config::withDBPrefix('oauth_clients') . '`');
    }
}
