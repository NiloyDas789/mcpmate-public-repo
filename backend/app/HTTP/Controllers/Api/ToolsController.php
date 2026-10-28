<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Stats\Stats;
use McpMate\Services\Tools\ToolRegistry;
use McpMate\Services\Tools\ToolsException;

/**
 * The tool catalog (docs/ui.md B1).
 *
 * GET /tools: every group and tool with its state, for the Tools page. No
 * MCP tool reaches it (the MCP layer builds tools/list itself).
 *
 * POST /tools: a site admin's save from the Tools page, in wp-admin only
 * (`wpAdmin`: never an MCP call, so no AI app can turn its own tools on).
 * Audited like every write.
 */
final class ToolsController extends RestController
{
    protected const CAPABILITIES = [
        'index'  => 'read',
        'update' => 'manage_options',
    ];

    public function index(Request $request): Response
    {
        return $this->ok([
            'groups' => ToolRegistry::groups(),
            'counts' => ToolRegistry::counts(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->auditTarget('tools', 'site');

        try {
            ToolRegistry::apply(
                (array) $request->get('tools', []),
                (array) $request->get('items', []),
                (array) $request->get('options', [])
            );
        } catch (ToolsException $e) {
            return $this->error($e->getMessage(), 400, $e->errorCode());
        }

        Stats::forget();

        return $this->ok([
            'groups' => ToolRegistry::groups(),
            'counts' => ToolRegistry::counts(),
        ]);
    }

    /**
     * REST `args` schema for update().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function updateArgs(): array
    {
        $object = static fn (string $description): array => [
            'type'                 => 'object',
            'description'          => $description,
            'additionalProperties' => true,
            'default'              => [],
        ];

        return [
            'tools'   => $object('Tool name => true or false.'),
            'items'   => $object('run_wp_cli or run_ability => item name => true or false.'),
            'options' => $object('Group options: svg => true or false.'),
        ];
    }
}
