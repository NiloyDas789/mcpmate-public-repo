<?php

namespace MCPMate\Services\Connection;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Config;
use MCPMate\Database\Connection as DB;
use MCPMate\Services\AuditLog\Logger;

/**
 * Site-wide read mode (docs/pro-parity.md P21): while it is on, every grant
 * reads only, whatever its access level. McpContext::begin() drops
 * site:write from the call's scopes (so tools/list shows only tools that
 * read), Router::authorizeMcp() and McpEndpoint refuse writes with
 * `mcpmate_read_mode`, Approvals::approve() leaves requests pending, MCPMate
 * Pro's background jobs and upload links stop, and the server instructions
 * start with one line that says so. Nothing is revoked, and wp-admin keeps
 * working.
 *
 * Option `MCPMATE_read_mode` `{at, by}`, autoloaded. isOn() reads the row
 * itself with a prepared SELECT, past `pre_option_*` / `option_*` filters
 * and the object cache, so no other plugin can make it look off; once per
 * request (on() and off() keep that copy current). A lookup that fails
 * counts as on and is not kept, so a database error never lets a write
 * through.
 */
final class ReadMode
{
    public const OPTION = 'read_mode';

    /** The first line of the server instructions while read mode is on. */
    public const INSTRUCTION = 'Read mode is on for this WordPress site: a site admin lets AI apps read it only. '
        . 'Every tool that would change something is hidden or refused with mcpmate_read_mode, '
        . 'and approval requests cannot be approved until it is turned off. '
        . 'Do not try other ways to make the change; tell the user that read mode is on.';

    /** @var array{at: string, by: int}|false|null The stored state this request (false: off), or null before the first read. */
    private static $state = null;

    public static function isOn(): bool
    {
        return self::state() !== null;
    }

    /**
     * `{at, by}` while on (`at` ISO 8601 UTC, `by` the user ID), else null.
     *
     * @return array{at: string, by: int}|null
     */
    public static function state(): ?array
    {
        if (self::$state === null) {
            $read = self::read();

            if ($read === null) {
                // The lookup failed: fail closed, and ask again next time.
                return ['at' => '', 'by' => 0];
            }

            self::$state = $read;
        }

        return self::$state === false ? null : self::$state;
    }

    /**
     * For the admin app (SERVER_VARIABLES.read_mode): `{on, at, by: {id, name}}`.
     *
     * @return array{on: bool, at: string|null, by: array{id: int, name: string|null}|null}
     */
    public static function summary(): array
    {
        $state = self::state();

        if ($state === null) {
            return ['on' => false, 'at' => null, 'by' => null];
        }

        $user = $state['by'] > 0 ? get_userdata($state['by']) : false;

        return [
            'on' => true,
            'at' => $state['at'] !== '' ? $state['at'] : null,
            'by' => ['id' => $state['by'], 'name' => $user ? $user->display_name : null],
        ];
    }

    /**
     * @return bool False when read mode was already on, or could not be
     *              saved (isOn() then says which). Nothing is audited then.
     */
    public static function on(int $userId): bool
    {
        if (self::isOn()) {
            return false;
        }

        $at = gmdate('Y-m-d H:i:s');

        if (!Config::updateOption(self::OPTION, ['at' => $at, 'by' => $userId], true)) {
            return false;
        }

        self::$state = ['at' => self::iso($at), 'by' => $userId];
        self::audit('read_mode_on', $userId);

        return true;
    }

    /**
     * @return bool False when read mode was off, or could not be turned off
     *              (isOn() then says which). Nothing is audited then.
     */
    public static function off(int $userId): bool
    {
        $state = self::state();

        if ($state === null || !Config::deleteOption(self::OPTION)) {
            return false;
        }

        self::$state = false;
        self::audit('read_mode_off', $userId, ['on_since' => $state['at'], 'turned_on_by' => $state['by']]);

        return true;
    }

    /**
     * Forget this request's copy (tests, or after the option changed elsewhere).
     */
    public static function reset(): void
    {
        self::$state = null;
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * The stored row, read from the options table of this site: false when
     * there is none, null when the lookup failed (DB::getVar() answers
     * null for both; wpdb resets last_error before each query).
     *
     * @return array{at: string, by: int}|false|null
     */
    private static function read()
    {
        global $wpdb;

        if (!is_object($wpdb)) {
            // No database (the unit tests): nothing can be stored either.
            return false;
        }

        $raw = DB::getVar(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1",
            [Config::withPrefix(self::OPTION)]
        );

        if ($raw === null && DB::lastError() !== '') {
            return null;
        }

        if (!is_string($raw) || $raw === '') {
            return false;
        }

        $saved = maybe_unserialize($raw);

        if (!is_array($saved) || !is_string($saved['at'] ?? null) || $saved['at'] === '') {
            // A row that is there but unreadable still means someone turned it on.
            return ['at' => '', 'by' => 0];
        }

        return ['at' => self::iso($saved['at']), 'by' => (int) ($saved['by'] ?? 0)];
    }

    private static function iso(string $mysql): string
    {
        return $mysql === '' ? '' : str_replace(' ', 'T', $mysql) . 'Z';
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function audit(string $action, int $userId, array $args = []): void
    {
        Logger::log([
            'tool'    => 'access',
            'action'  => $action,
            'target'  => 'site',
            'args'    => $args,
            'result'  => Logger::RESULT_OK,
            'user_id' => $userId,
        ]);
    }
}
