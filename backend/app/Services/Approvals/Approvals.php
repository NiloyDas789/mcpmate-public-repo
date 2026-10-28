<?php

namespace McpMate\Services\Approvals;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\HTTP\ErrorPresenter;
use McpMate\Operations\Cli\ApprovedCommand;
use McpMate\Services\Abilities\ApprovedAbility;
use McpMate\Services\AuditLog\Logger;
use McpMate\Services\ChangeHistory\ChangeCapture;
use McpMate\Services\Mcp\McpContext;
use McpMate\Services\ThemeDraft\ApprovedThemeChange;

/**
 * Destructive operations that wait for a site admin.
 *
 * 1. An MCP endpoint runs the operation as a dry run, then request()
 *    stores the original arguments and the preview, and hands the caller
 *    an approval ID, a one-time token, the wp-admin URL and the expiry.
 * 2. A site admin opens the URL (login + manage_options; Approve and
 *    Reject are nonce-checked AJAX calls) and decides.
 * 3. approve() claims the row (pending → approved, atomically, only before
 *    expires_at), runs the operation once through the tool's executor and
 *    stores the outcome (approved → executed). reject() only claims it.
 * 4. The caller polls with the ID and token (forRequester()) and reads
 *    the stored result.
 *
 * Executors are an allowlist with no filter: a tool that is not in
 * EXECUTORS cannot be queued, and a stored row for an unknown tool is
 * never run.
 */
final class Approvals
{
    /** Seconds an approval stays pending (15 minutes). */
    public const TTL = 900;

    /**
     * Tool => callable(array $args, int $requestedBy): array. The callable
     * re-checks everything the original request checked and throws
     * ApprovalException to refuse.
     *
     * @var array<string, callable-string|array{0: class-string, 1: string}>
     */
    private const EXECUTORS = [
        'abilities'   => [ApprovedAbility::class, 'run'],
        'cli'         => [ApprovedCommand::class, 'run'],
        'theme_draft' => [ApprovedThemeChange::class, 'run'],
    ];

    // ──────────────────────────────────────────────
    //  Requesting
    // ──────────────────────────────────────────────

    /**
     * Store a request for approval.
     *
     * @param array<string, mixed> $args    Exactly what the executor will run.
     * @param array<string, mixed> $preview The dry-run result shown to the admin.
     * @return array{id: int, token: string, approval_url: string, expires_at: string}
     * @throws ApprovalException When the tool has no executor or the row cannot be written.
     */
    public static function request(string $tool, string $action, string $target, array $args, array $preview, string $requestId = ''): array
    {
        if (!isset(self::EXECUTORS[$tool])) {
            throw new ApprovalException(
                esc_html__('This operation cannot be queued for approval.', 'mcpmate'),
                esc_html(ApprovalException::UNKNOWN_TOOL),
                500
            );
        }

        $token     = ApprovalToken::generate();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TTL);
        $argsJson  = wp_json_encode($args);
        $preview   = wp_json_encode($preview);

        $id = $argsJson === false ? false : ApprovalStore::insert([
            'token_hash'   => ApprovalToken::hash($token),
            'created_by'   => get_current_user_id(),
            // check_approval finds the request by the MCP connection that made it (docs/mcp-in-plugin.md §7.3).
            'grant_id'     => McpContext::grantId(),
            'request_id'   => $requestId,
            'tool'         => $tool,
            'action'       => $action,
            'target'       => $target,
            'args_json'    => $argsJson,
            'preview_json' => $preview === false ? 'null' : $preview,
            'expires_at'   => $expiresAt,
        ]);

        if ($id === false) {
            throw new ApprovalException(
                esc_html__('The request could not be queued for approval. Nothing was changed.', 'mcpmate'),
                esc_html(ApprovalException::STORE_FAILED),
                500,
                esc_html__('Ask a site admin to deactivate and reactivate McpMate so its database tables are created.', 'mcpmate')
            );
        }

        return [
            'id'           => $id,
            'token'        => $token,
            'approval_url' => self::url($id),
            'expires_at'   => self::isoDate($expiresAt),
        ];
    }

    /**
     * The wp-admin page where a site admin reviews approval $id.
     * A query argument rather than the SPA's #/ route, so it survives the
     * login redirect.
     */
    public static function url(int $id): string
    {
        return admin_url('admin.php?page=' . Config::SLUG . '&approval=' . $id);
    }

    // ──────────────────────────────────────────────
    //  Reading
    // ──────────────────────────────────────────────

    /**
     * The requester's view of an approval: null unless $userId created it
     * and $token matches, so other users cannot tell whether it exists.
     *
     * @return array<string, mixed>|null
     */
    public static function forRequester(int $id, string $token, int $userId): ?array
    {
        $row = ApprovalStore::find($id);

        if (
            $row === null
            || $userId < 1
            || (int) $row['created_by'] !== $userId
            || !ApprovalToken::matches($token, (string) $row['token_hash'])
        ) {
            return null;
        }

        return self::requesterView($row);
    }

    /**
     * The requester's view for the MCP connection that asked: null unless
     * $userId created it through grant $grantId. No token: the row itself
     * records who asked (check_approval, docs/mcp-in-plugin.md §7.3).
     *
     * @return array<string, mixed>|null
     */
    public static function forGrant(int $id, int $userId, string $grantId): ?array
    {
        $row = ApprovalStore::find($id);

        if (
            $row === null
            || $userId < 1
            || $grantId === ''
            || (int) $row['created_by'] !== $userId
            || !hash_equals((string) ($row['grant_id'] ?? ''), $grantId)
        ) {
            return null;
        }

        return self::requesterView($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function requesterView(array $row): array
    {
        $view   = self::view($row);
        $public = ['id', 'status', 'tool', 'action', 'target', 'args', 'created_at', 'expires_at', 'decided_at', 'executed_at', 'result'];
        $view   = array_intersect_key($view, array_flip($public));

        if ($view['status'] === ApprovalStore::STATUS_PENDING) {
            $view['approval_url'] = self::url((int) $row['id']);
        }

        return $view;
    }

    /**
     * The site admin's view: everything except the token hash.
     *
     * @return array<string, mixed>|null
     */
    public static function get(int $id): ?array
    {
        $row = ApprovalStore::find($id);

        return $row === null ? null : self::view($row);
    }

    /**
     * One page of approvals for the admin list, newest first, without the
     * (possibly large) preview and result.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public static function list(string $status, int $page, int $perPage): array
    {
        $page = ApprovalStore::paginate($status, $page, $perPage);

        $userIds = array_values(array_unique(array_filter(array_map('intval', array_column($page['rows'], 'created_by')))));
        if ($userIds !== []) {
            cache_users($userIds);
        }

        return [
            'items' => array_map(
                static fn (array $row) => array_diff_key(self::view($row), ['preview' => true, 'result' => true]),
                $page['rows']
            ),
            'total' => $page['total'],
        ];
    }

    /**
     * Status as callers should see it: a pending row past its expiry is
     * expired even before the cron or a decision marks it.
     */
    public static function effectiveStatus(string $status, string $expiresAt, string $now): string
    {
        return $status === ApprovalStore::STATUS_PENDING && $expiresAt <= $now
            ? ApprovalStore::STATUS_EXPIRED
            : $status;
    }

    // ──────────────────────────────────────────────
    //  Deciding (site admin, from wp-admin)
    // ──────────────────────────────────────────────

    /**
     * Approve and run approval $id, once. The capability and nonce checks
     * belong to the caller (the AJAX route); the executor re-checks the
     * operation's own capability and allowlist before it runs.
     *
     * @return array<string, mixed> The admin view after execution.
     * @throws ApprovalException 404 unknown ID, 409 expired or already decided.
     */
    public static function approve(int $id, int $adminId): array
    {
        self::claim($id, ApprovalStore::STATUS_APPROVED, $adminId);

        $row = (array) ApprovalStore::find($id);

        // What the approved operation changes is history too, linked to this audit row.
        ChangeCapture::begin();
        $outcome = self::execute($row);

        ApprovalStore::finish($id, $outcome);

        $error   = $outcome['error'];
        $auditId = Logger::log([
            'tool'       => (string) $row['tool'],
            'action'     => (string) $row['action'] !== '' ? (string) $row['action'] : 'approved',
            'target'     => (string) $row['target'],
            'args'       => ['approval_id' => $id] + (array) json_decode((string) $row['args_json'], true),
            'result'     => $outcome['ok'] ? Logger::RESULT_OK : Logger::RESULT_ERROR,
            'message'    => sprintf(
                'Approval #%d (requested by user #%d) approved in wp-admin%s',
                $id,
                (int) $row['created_by'],
                is_array($error) ? '; failed: ' . $error['code'] . ': ' . $error['message'] : '; ran once.'
            ),
            'request_id' => (string) $row['request_id'],
            'user_id'    => $adminId,
        ]);

        ChangeCapture::end([
            'audit_id'   => is_int($auditId) ? $auditId : 0,
            'request_id' => (string) $row['request_id'],
            'user_id'    => $adminId,
            'tool'       => (string) $row['tool'],
            'mcp_tool'   => self::mcpTool((string) $row['tool'], (string) $row['action']),
        ]);

        return (array) self::get($id);
    }

    /**
     * The MCP tool that asked for an approval of $tool / $action.
     */
    private static function mcpTool(string $tool, string $action): string
    {
        if ($tool === 'theme_draft') {
            return str_contains($action, 'rollback') ? 'rollback_theme' : 'publish_draft_theme';
        }

        return ['cli' => 'run_wp_cli', 'abilities' => 'run_ability'][$tool] ?? '';
    }

    /**
     * Reject approval $id. Nothing runs.
     *
     * @return array<string, mixed> The admin view.
     * @throws ApprovalException 404 unknown ID, 409 expired or already decided.
     */
    public static function reject(int $id, int $adminId): array
    {
        self::claim($id, ApprovalStore::STATUS_REJECTED, $adminId);

        $row = (array) ApprovalStore::find($id);

        Logger::log([
            'tool'       => 'approvals',
            'action'     => 'reject',
            'target'     => 'approval:' . $id,
            'args'       => ['approval_id' => $id, 'tool' => $row['tool'], 'action' => $row['action'], 'target' => $row['target']],
            'result'     => Logger::RESULT_OK,
            'message'    => sprintf('Approval #%d (requested by user #%d) rejected in wp-admin.', $id, (int) $row['created_by']),
            'request_id' => (string) $row['request_id'],
            'user_id'    => $adminId,
        ]);

        return (array) self::get($id);
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * pending → $status for exactly one caller, or an exception explaining why not.
     *
     * @throws ApprovalException
     */
    private static function claim(int $id, string $status, int $adminId): void
    {
        $row = ApprovalStore::find($id);

        if ($row === null) {
            throw new ApprovalException(
                esc_html__('Approval request not found.', 'mcpmate'),
                esc_html(ApprovalException::NOT_FOUND),
                404
            );
        }

        if (ApprovalStore::decide($id, $status, $adminId)) {
            return;
        }

        $current = self::effectiveStatus((string) $row['status'], (string) $row['expires_at'], ApprovalStore::now());

        if ($current === ApprovalStore::STATUS_EXPIRED) {
            ApprovalStore::expireStale();

            throw new ApprovalException(
                esc_html__('This approval request has expired. Nothing was run.', 'mcpmate'),
                esc_html(ApprovalException::EXPIRED),
                409,
                esc_html__('Ask the AI to send the request again.', 'mcpmate')
            );
        }

        // The stored row may predate a concurrent decision; report the latest state.
        $latest = ApprovalStore::find($id);

        throw new ApprovalException(
            sprintf(
                /* translators: %s: approval status, e.g. executed. */
                esc_html__('This approval request was already decided (%s).', 'mcpmate'),
                $latest !== null ? esc_html((string) $latest['status']) : esc_html((string) $row['status'])
            ),
            esc_html(ApprovalException::ALREADY_DECIDED),
            409
        );
    }

    /**
     * Run an approved row through its tool's executor. Never throws: the
     * outcome is stored either way, so the row cannot be run again.
     *
     * @param array<string, mixed> $row
     * @return array{ok: bool, data: mixed, error: array{code: string, message: string, hint: string}|null}
     */
    private static function execute(array $row): array
    {
        $executor = self::EXECUTORS[(string) ($row['tool'] ?? '')] ?? null;
        $args     = json_decode((string) ($row['args_json'] ?? ''), true);

        try {
            if ($executor === null || !is_array($args)) {
                throw new ApprovalException(
                    esc_html__('This operation cannot be run.', 'mcpmate'),
                    esc_html(ApprovalException::UNKNOWN_TOOL),
                    500
                );
            }

            return ['ok' => true, 'data' => call_user_func($executor, $args, (int) $row['created_by']), 'error' => null];
        } catch (ApprovalException $e) {
            return [
                'ok'    => false,
                'data'  => null,
                'error' => ['code' => $e->errorCode(), 'message' => ErrorPresenter::plain($e), 'hint' => ErrorPresenter::text($e->hint())],
            ];
        } catch (\Throwable $e) {
            // Class name only: exception messages can carry paths or SQL.
            return [
                'ok'    => false,
                'data'  => null,
                'error' => [
                    'code'    => 'mcpmate_approval_failed',
                    'message' => __('The operation failed unexpectedly.', 'mcpmate'),
                    'hint'    => get_class($e),
                ],
            ];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function view(array $row): array
    {
        $args = json_decode((string) $row['args_json'], true);

        return [
            'id'          => (int) $row['id'],
            'status'      => self::effectiveStatus((string) $row['status'], (string) $row['expires_at'], ApprovalStore::now()),
            'tool'        => (string) $row['tool'],
            'action'      => (string) $row['action'],
            'target'      => (string) $row['target'],
            'args'        => is_array($args) ? $args : [],
            'preview'     => json_decode((string) $row['preview_json'], true),
            'result'      => $row['result_json'] !== null ? json_decode((string) $row['result_json'], true) : null,
            'request_id'  => (string) $row['request_id'],
            'created_at'  => self::isoDate((string) $row['created_at']),
            'created_by'  => self::user((int) $row['created_by']),
            'expires_at'  => self::isoDate((string) $row['expires_at']),
            'decided_at'  => self::isoDate($row['decided_at'] !== null ? (string) $row['decided_at'] : ''),
            'decided_by'  => (int) $row['decided_by'] > 0 ? self::user((int) $row['decided_by']) : null,
            'executed_at' => self::isoDate($row['executed_at'] !== null ? (string) $row['executed_at'] : ''),
        ];
    }

    /**
     * @return array{id: int, login: string|null, display_name: string|null}
     */
    private static function user(int $id): array
    {
        $user = $id > 0 ? get_userdata($id) : false;

        return [
            'id'           => $id,
            'login'        => $user ? $user->user_login : null,
            'display_name' => $user ? $user->display_name : null,
        ];
    }

    /**
     * `Y-m-d H:i:s` (UTC) → `Y-m-dTH:i:sZ`; empty → null.
     */
    private static function isoDate(string $value): ?string
    {
        return $value === '' ? null : str_replace(' ', 'T', $value) . 'Z';
    }
}
