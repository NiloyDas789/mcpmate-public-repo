<?php

namespace McpMate\Services\Connection;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Services\AuditLog\Logger;

/**
 * Pause AI access (docs/ui.md B3): while paused, every MCP tool call is
 * refused with `mcpmate_access_paused` (McpEndpoint, and
 * Router::authorizeMcp() for each route a tool dispatches). Nothing is
 * revoked. Pending approvals stay decidable in wp-admin.
 *
 * Option `MCPMATE_access_paused` `{at, by}`, autoloaded: it is read on
 * every MCP call. Pausing and resuming are audited.
 */
final class AccessPause
{
    public const OPTION = 'access_paused';

    public static function isPaused(): bool
    {
        return self::state() !== null;
    }

    /**
     * `{at, by}` while paused (`at` ISO 8601 UTC, `by` the user ID), else null.
     *
     * @return array{at: string, by: int}|null
     */
    public static function state(): ?array
    {
        $saved = Config::getOption(self::OPTION, null);

        if (!is_array($saved) || !is_string($saved['at'] ?? null) || $saved['at'] === '') {
            return null;
        }

        return ['at' => str_replace(' ', 'T', $saved['at']) . 'Z', 'by' => (int) ($saved['by'] ?? 0)];
    }

    /**
     * For the admin app (SERVER_VARIABLES.access): `{paused, at, by: {id, name}}`.
     *
     * @return array{paused: bool, at: string|null, by: array{id: int, name: string|null}|null}
     */
    public static function summary(): array
    {
        $state = self::state();

        if ($state === null) {
            return ['paused' => false, 'at' => null, 'by' => null];
        }

        $user = $state['by'] > 0 ? get_userdata($state['by']) : false;

        return [
            'paused' => true,
            'at'     => $state['at'],
            'by'     => ['id' => $state['by'], 'name' => $user ? $user->display_name : null],
        ];
    }

    /**
     * @return bool False when access was already paused (nothing changed).
     */
    public static function pause(int $userId): bool
    {
        if (self::isPaused()) {
            return false;
        }

        Config::updateOption(self::OPTION, ['at' => gmdate('Y-m-d H:i:s'), 'by' => $userId], true);
        self::audit('pause', $userId);

        return true;
    }

    /**
     * @return bool False when access was not paused (nothing changed).
     */
    public static function resume(int $userId): bool
    {
        $state = self::state();

        if ($state === null) {
            return false;
        }

        Config::deleteOption(self::OPTION);
        self::audit('resume', $userId, ['paused_at' => $state['at'], 'paused_by' => $state['by']]);

        return true;
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
