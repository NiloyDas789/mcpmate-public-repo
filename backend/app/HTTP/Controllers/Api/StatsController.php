<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Stats\Stats;

/**
 * GET /stats: the Dashboard's numbers (Stats, cached for 5 minutes).
 * A site admin in wp-admin only (`wpAdmin`), never an MCP call.
 */
final class StatsController extends RestController
{
    public function show(Request $request): Response
    {
        return $this->ok(Stats::summary());
    }
}
