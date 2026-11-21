<?php

namespace MCPMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\HTTP\Request\Request;
use MCPMate\HTTP\Response;
use MCPMate\HTTP\RestEnvelope;
use MCPMate\Services\Stats\Stats;
use MCPMate\Services\Tools\ToolRegistry;
use MCPMate\Services\Tools\ToolsException;

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
    /**
     * Bytes of RestEnvelope's cap kept for everything around `groups`: the
     * envelope's keys, its request ID and `counts` (under 200 bytes).
     */
    private const ENVELOPE_ROOM = 1024;

    protected const CAPABILITIES = [
        'index'  => 'read',
        'update' => 'manage_options',
    ];

    public function index(Request $request): Response
    {
        return $this->ok(self::catalog());
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

        return $this->ok(self::catalog());
    }

    /**
     * Every group and the counts, with ability descriptions cut to fit
     * RestEnvelope's cap before it would cut abilities or groups.
     *
     * @return array{groups: array<int, array<string, mixed>>, counts: array{enabled: int, total: int}}
     */
    private static function catalog(): array
    {
        return [
            'groups' => ToolRegistry::fitAbilityDescriptions(ToolRegistry::groups(), RestEnvelope::MAX_BYTES - self::ENVELOPE_ROOM),
            'counts' => ToolRegistry::counts(),
        ];
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
