<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\AuditLog\Logger;
use McpMate\Services\Mcp\Skills\SkillStore;

/**
 * Tools → Skills (docs/mcp-in-plugin.md §6.1): a site admin sees the skills
 * AI apps saved for this site's users, and can delete one.
 *
 * AJAX routes (nonce + admin).
 */
final class SkillsController
{
    public function index(Request $request): Response
    {
        return Response::success(['skills' => SkillStore::all()]);
    }

    /**
     * `{id}`.
     */
    public function delete(Request $request): Response
    {
        $id = absint($request->get('id', 0));

        if ($id < 1 || !SkillStore::delete($id)) {
            return Response::error(__('Skill not found.', 'mcpmate'), 404, 'mcpmate_skill_not_found');
        }

        Logger::log([
            'tool'   => 'skills',
            'action' => 'delete',
            'target' => 'skill:' . $id,
            'result' => Logger::RESULT_OK,
        ]);

        return Response::success(['message' => __('Skill deleted.', 'mcpmate'), 'skills' => SkillStore::all()]);
    }
}
