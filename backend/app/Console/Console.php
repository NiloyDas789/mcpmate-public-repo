<?php

namespace MCPMate\Console;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Config;
use MCPMate\Services\Approvals\ApprovalStore;
use MCPMate\Services\AuditLog\AuditLogQuery;
use MCPMate\Services\AuditLog\Logger;
use MCPMate\Services\Connection\AccessPause;
use MCPMate\Services\Connection\Connection;
use MCPMate\Services\Connection\GrantRevoker;
use MCPMate\Services\Connection\Grants;
use MCPMate\Services\Connection\ReadMode;
use MCPMate\Services\Connection\RestGuard;
use MCPMate\Services\Stats\Stats;
use MCPMate\Services\Tools\ToolCatalog;
use MCPMate\Services\Tools\ToolRegistry;
use MCPMate\Services\Tools\ToolSettings;
use MCPMate\Services\Tools\ToolsException;

/**
 * What `wp mcpmate` does (docs/pro-parity.md P21, security rule 9): the same
 * services wp-admin calls, run as WP-CLI's `--user` (0 without one), each
 * call audited with `auth` `cli` (RestGuard::asConsole()). The WP-CLI
 * classes in Commands/ only parse arguments and print; this class holds
 * the work, so the integration tests run it without WP-CLI.
 *
 * Reads are audited too (kind `read`), so the log shows who looked at the
 * site's AI access from the server.
 */
final class Console
{
    /** Most rows `audit tail` prints. */
    public const MAX_TAIL = AuditLogQuery::MAX_PER_PAGE;

    // ──────────────────────────────────────────────
    //  Status, pause, read mode
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        return self::run(static function (): array {
            $access = AccessPause::summary();
            $read   = ReadMode::summary();
            $counts = ToolRegistry::counts();

            self::auditRead('access', 'status');

            return [
                'version'           => Config::VERSION,
                'mcp_url'           => Connection::summary()['mcp_url'],
                'paused'            => $access['paused'],
                'paused_since'      => $access['at'],
                'paused_by'         => $access['by']['name'] ?? null,
                'read_mode'         => $read['on'],
                'read_mode_since'   => $read['at'],
                'read_mode_by'      => $read['by']['name'] ?? null,
                'ai_apps_connected' => Grants::countLive(),
                'pending_approvals' => ApprovalStore::countPending(),
                'tools_enabled'     => $counts['enabled'],
                'tools_total'       => $counts['total'],
                'pro'               => Config::isProActivated() ? 'active' : (Config::isProInstalled() ? 'installed' : 'missing'),
            ];
        });
    }

    /**
     * @return bool False when access was already paused.
     */
    public static function pause(): bool
    {
        return self::run(static fn (): bool => AccessPause::pause(get_current_user_id()));
    }

    /**
     * @return bool False when access was not paused.
     */
    public static function resume(): bool
    {
        return self::run(static fn (): bool => AccessPause::resume(get_current_user_id()));
    }

    /**
     * `on`, `off` or `status`.
     *
     * @return array{changed: bool, read_mode: array<string, mixed>}
     * @throws ConsoleException For any other action, or when the change could not be saved.
     */
    public static function readMode(string $action): array
    {
        return self::run(static function () use ($action): array {
            switch ($action) {
                case 'on':
                    $changed = ReadMode::on(get_current_user_id());
                    break;
                case 'off':
                    $changed = ReadMode::off(get_current_user_id());
                    break;
                case 'status':
                    $changed = false;
                    self::auditRead('access', 'read_mode_status');
                    break;
                default:
                    throw new ConsoleException(
                        /* translators: %s: what was typed. */
                        sprintf(esc_html__('"%s" is not on, off or status.', 'mcpmate'), esc_html($action)),
                        'mcpmate_cli_invalid_argument'
                    );
            }

            if (!$changed && $action !== 'status' && ReadMode::isOn() !== ($action === 'on')) {
                throw new ConsoleException(esc_html__('Read mode could not be saved. Nothing changed.', 'mcpmate'), 'mcpmate_read_mode_not_saved');
            }

            return ['changed' => $changed, 'read_mode' => ReadMode::summary()];
        });
    }

    // ──────────────────────────────────────────────
    //  Connected AI apps
    // ──────────────────────────────────────────────

    /**
     * One row per grant, live ones first (as Connections lists them).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function grants(): array
    {
        return self::run(static function (): array {
            self::auditRead('connection', 'list');

            return array_map(static fn (array $grant): array => [
                'grant_id'     => $grant['grant_id'],
                'client_name'  => $grant['client_name'],
                'user'         => $grant['user']['login'] ?? ('#' . $grant['user']['id']),
                'access'       => $grant['access'],
                'status'       => $grant['status'],
                'created_at'   => $grant['created_at'],
                'last_used_at' => $grant['last_used_at'],
            ], Grants::all());
        });
    }

    /**
     * Revoke one grant, as Connections → Revoke does: every code and token
     * of it dies with it.
     *
     * @return array<string, mixed> The grant after.
     * @throws ConsoleException Unknown or already revoked.
     */
    public static function revokeGrant(string $grantId): array
    {
        return self::run(static function () use ($grantId): array {
            $grant = Grants::isGrantId($grantId) ? Grants::find($grantId) : null;

            if ($grant === null) {
                throw new ConsoleException(esc_html__('No AI app has that grant ID. Run "wp mcpmate grants list".', 'mcpmate'), 'mcpmate_grant_unknown');
            }

            if (!GrantRevoker::revoke($grantId, get_current_user_id(), Grants::REASON_ADMIN)) {
                throw new ConsoleException(esc_html__('That AI app\'s access was already revoked.', 'mcpmate'), 'mcpmate_grant_revoked');
            }

            return Grants::format((array) Grants::find($grantId));
        });
    }

    // ──────────────────────────────────────────────
    //  Tools
    // ──────────────────────────────────────────────

    /**
     * Every catalog tool, in the Tools page's order.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tools(): array
    {
        return self::run(static function (): array {
            $rows = [];

            foreach (ToolRegistry::groups() as $group) {
                foreach ($group['tools'] as $tool) {
                    $rows[] = [
                        'name'      => $tool['name'],
                        'group'     => $group['label'],
                        'kind'      => $tool['kind'],
                        'approval'  => $tool['approval'] ? 'yes' : 'no',
                        'enabled'   => $tool['enabled'] ? 'on' : 'off',
                        'available' => $group['available'] ? 'yes' : (string) $group['unavailable_reason'],
                    ];
                }
            }

            self::auditRead('tools', 'list');

            return $rows;
        });
    }

    /**
     * Turn one tool on or off, as a change on the Tools page does (the same
     * checks: a catalog tool, never an always-on one).
     *
     * @return bool False when it already was.
     * @throws ConsoleException
     */
    public static function setTool(string $name, bool $enabled): bool
    {
        return self::run(static function () use ($name, $enabled): bool {
            if (ToolCatalog::tool($name) === null) {
                throw new ConsoleException(
                    /* translators: %s: tool name. */
                    sprintf(esc_html__('"%s" is not a tool on this site. Run "wp mcpmate tools list".', 'mcpmate'), esc_html($name)),
                    'mcpmate_unknown_tool'
                );
            }

            $was = ToolSettings::isEnabled($name);

            try {
                $changes = ToolRegistry::apply([$name => $enabled], [], []);
            } catch (ToolsException $e) {
                throw new ConsoleException(esc_html($e->getMessage()), esc_html($e->errorCode()));
            }

            Stats::forget();
            Logger::log([
                'tool'   => 'tools',
                'action' => 'update',
                'target' => 'site',
                'args'   => $changes,
                'result' => Logger::RESULT_OK,
            ]);

            return $was !== $enabled;
        });
    }

    // ──────────────────────────────────────────────
    //  Audit log
    // ──────────────────────────────────────────────

    /**
     * The newest $count rows, oldest of them first (as `tail` prints).
     *
     * @return array<int, array<string, mixed>>
     * @throws ConsoleException For a grant ID that is not one.
     */
    public static function auditTail(int $count, string $grantId = ''): array
    {
        return self::run(static function () use ($count, $grantId): array {
            if ($grantId !== '' && !Grants::isGrantId($grantId)) {
                throw new ConsoleException(esc_html__('That is not a grant ID. Run "wp mcpmate grants list".', 'mcpmate'), 'mcpmate_grant_unknown');
            }

            $count   = max(1, min(self::MAX_TAIL, $count));
            $filters = $grantId !== '' ? ['grant_id' => $grantId] : [];
            $items   = AuditLogQuery::paginate($filters, 1, $count)['items'];

            self::auditRead('audit_log', 'tail', ['n' => $count, 'grant_id' => $grantId]);

            return array_map(static fn (array $item): array => [
                'id'          => $item['id'],
                'created_at'  => $item['created_at'],
                'tool'        => $item['tool'],
                'action'      => $item['action'],
                'result'      => $item['result'],
                'auth'        => $item['auth'] ?? '',
                'client_name' => $item['client_name'] ?? null,
                'mcp_tool'    => $item['mcp_tool'] ?? '',
                'target'      => $item['target'],
            ], array_reverse($items));
        });
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @template T
     * @param callable(): T $action
     * @return T
     */
    private static function run(callable $action)
    {
        return RestGuard::asConsole($action);
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function auditRead(string $tool, string $action, array $args = []): void
    {
        Logger::log([
            'tool'   => $tool,
            'action' => $action,
            'target' => 'site',
            'args'   => $args,
            'result' => Logger::RESULT_OK,
            'kind'   => Logger::KIND_READ,
        ]);
    }
}
