<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\AuditLog\Logger;
use McpMate\Services\Connection\GrantRevoker;
use McpMate\Services\Connection\Grants;
use McpMate\Services\Tools\ToolCatalog;

/**
 * The Connected AI apps page: every grant, with Revoke and Revoke all.
 *
 * AJAX routes (nonce + admin). A revoke takes effect at once, with every
 * token of the grant.
 */
final class ConnectedAppsController
{
    public function index(Request $request): Response
    {
        return Response::success([
            'grants' => Grants::all(),
            'limit'  => Grants::limit(),
        ]);
    }

    public function revoke(Request $request): Response
    {
        $grantId = (string) $request->get('grant_id', '');
        $grant   = Grants::isGrantId($grantId) ? Grants::find($grantId) : null;

        if ($grant === null) {
            return Response::error(__('That AI app was not found.', 'mcpmate'), 404, 'mcpmate_grant_unknown');
        }

        if (!GrantRevoker::revoke($grantId, get_current_user_id(), Grants::REASON_ADMIN)) {
            return Response::error(__('That AI app\'s access was already revoked.', 'mcpmate'), 409, 'mcpmate_grant_revoked');
        }

        return Response::success(['grant' => Grants::format((array) Grants::find($grantId))]);
    }

    /**
     * `grants/update {grant_id, access, tools}`: a site admin changes an AI
     * app's access level (docs/ui.md B2). Never over REST (rule 9). Custom
     * access needs at least one tool, each one in the catalog. Applies to
     * the app's next request; writes one audit row.
     */
    public function update(Request $request): Response
    {
        $grantId = (string) $request->get('grant_id', '');
        $grant   = Grants::isGrantId($grantId) ? Grants::find($grantId) : null;

        if ($grant === null) {
            return Response::error(__('That AI app was not found.', 'mcpmate'), 404, 'mcpmate_grant_unknown');
        }

        if ($grant['status'] === Grants::STATUS_REVOKED) {
            return Response::error(__('That AI app\'s access was already revoked.', 'mcpmate'), 409, 'mcpmate_grant_revoked');
        }

        $access = (string) $request->get('access', '');
        if (!in_array($access, Grants::ACCESSES, true)) {
            return Response::error(__('Choose full access, read-only or custom.', 'mcpmate'), 400, 'mcpmate_invalid_access');
        }

        $tools = $request->get('tools', []);

        // FormData sends arrays as JSON strings.
        if (is_string($tools)) {
            $tools = json_decode($tools, true);
        }

        $tools = is_array($tools) ? array_values(array_unique(array_filter($tools, 'is_string'))) : null;

        if ($access === Grants::ACCESS_CUSTOM) {
            $unknown = $tools === null ? [] : array_values(array_diff($tools, ToolCatalog::names()));

            if ($tools === null || $tools === [] || $unknown !== []) {
                return Response::error(
                    __('Custom access needs a list of one or more tools from the Tools page.', 'mcpmate'),
                    400,
                    'mcpmate_invalid_access'
                );
            }
        }

        $before = ['access' => Grants::access($grant), 'tools' => Grants::tools($grant)];

        if (!Grants::updateAccess($grantId, $access, $access === Grants::ACCESS_CUSTOM ? (array) $tools : [])) {
            return Response::error(__('The access could not be saved.', 'mcpmate'), 500, 'mcpmate_grant_update_failed');
        }

        $updated = (array) Grants::find($grantId);

        Logger::log([
            'tool'     => 'connection',
            'action'   => 'grant_access',
            'target'   => 'grant:' . $grantId,
            'args'     => ['from' => $before, 'to' => ['access' => Grants::access($updated), 'tools' => Grants::tools($updated)]],
            'result'   => Logger::RESULT_OK,
            'grant_id' => $grantId,
        ]);

        return Response::success([
            'message' => __('Access saved. The AI app may need to reconnect, or start a new chat, to see the change.', 'mcpmate'),
            'grant'   => Grants::format($updated),
        ]);
    }

    public function revokeAll(Request $request): Response
    {
        return Response::success(['revoked' => count(GrantRevoker::revokeAll(get_current_user_id(), Grants::REASON_ADMIN))]);
    }
}
