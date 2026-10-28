<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\SiteContext\SiteInfo;

/**
 * GET /site-info — versions, theme, plugins, builders, SEO, WooCommerce,
 * permalinks and Site Health counts, so the AI knows the site before changing it.
 *
 * Uses the default capability (manage_options): core shows the plugin list,
 * server versions and Site Health only to administrators.
 */
final class SiteInfoController extends RestController
{
    public function show(Request $request): Response
    {
        return $this->ok(SiteInfo::collect());
    }
}
