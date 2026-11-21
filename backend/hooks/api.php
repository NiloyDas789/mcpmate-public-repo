<?php

/**
 * REST API route definitions.
 *
 * These routes are registered under the `mcpmate/v1` namespace.
 * The $router variable is provided by HookProvider before this file is included.
 *
 * Middleware aliases are defined in Plugin::middlewares(). Routes without
 * middleware are denied. Use the narrowest capability for each route, e.g.
 * ->middleware('can:edit_posts'), and declare input with ->args([...]) so
 * WordPress validates and sanitizes it before the controller runs.
 * Do not use 'nonce' here (MCP calls dispatch routes in-process, without
 * one) and never use 'public'. An MCP call's tools run as their grant's
 * WordPress user and reach a route only through its tool in ToolCatalog:
 * add every new MCP route to that map. Routes only a site admin in
 * wp-admin may use add 'wpAdmin', which refuses MCP calls.
 *
 * Every response under this namespace is wrapped in the RestEnvelope
 * { ok, data, error, request_id, truncated }.
 *
 * MCPMate Pro declares its routes (media, theme drafts, core content,
 * Elementor) in its own file, with this
 * router (Feature::registerRestRoutes(), from HookProvider).
 */

use MCPMate\HTTP\Router\Route;
use MCPMate\HTTP\Controllers\Api\AbilitiesController;
use MCPMate\HTTP\Controllers\Api\ApprovalsController;
use MCPMate\HTTP\Controllers\Api\AuditLogController;
use MCPMate\HTTP\Controllers\Api\BlocksController;
use MCPMate\HTTP\Controllers\Api\CliController;
use MCPMate\HTTP\Controllers\Api\DbController;
use MCPMate\HTTP\Controllers\Api\PageHtmlController;
use MCPMate\HTTP\Controllers\Api\PingController;
use MCPMate\HTTP\Controllers\Api\PostStructureController;
use MCPMate\HTTP\Controllers\Api\SiteInfoController;
use MCPMate\HTTP\Controllers\Api\StatsController;
use MCPMate\HTTP\Controllers\Api\ToolsController;

if (!defined('ABSPATH')) {
    exit;
}

// MCP endpoints. Each route requires its controller method's capability
// (RestController::DEFAULT_CAPABILITY, manage_options, unless overridden).

// GET /wp-json/mcpmate/v1/ping
Route::get('ping', [PingController::class, 'ping'])->middleware(PingController::permission('ping'));

// Tools (docs/ui.md B1). A site admin in wp-admin reads and changes the catalog.

// GET /wp-json/mcpmate/v1/tools
Route::get('tools', [ToolsController::class, 'index'])->middleware(ToolsController::permission('index'));

// POST /wp-json/mcpmate/v1/tools { tools, items, options }
Route::post('tools', [ToolsController::class, 'update'])
    ->middleware(ToolsController::permission('update'), 'wpAdmin')
    ->args(ToolsController::updateArgs());

// GET /wp-json/mcpmate/v1/stats (the Dashboard's numbers; cached 5 minutes)
Route::get('stats', [StatsController::class, 'show'])->middleware(StatsController::permission('show'), 'wpAdmin');

// GET /wp-json/mcpmate/v1/audit-log?page=&per_page=&id=&tool=&mcp_tool=&kind=&result=&grant_id=&user_id=&search=&from=&to=&order_by=&order=
Route::get('audit-log', [AuditLogController::class, 'index'])
    ->middleware(AuditLogController::permission('index'))
    ->args(AuditLogController::indexArgs());

// Site context (read-only): what the AI looks at before changing anything.

// GET /wp-json/mcpmate/v1/site-info
Route::get('site-info', [SiteInfoController::class, 'show'])->middleware(SiteInfoController::permission('show'));

// GET /wp-json/mcpmate/v1/page-html?url=|post_id=&strip_scripts=&max_bytes=
Route::get('page-html', [PageHtmlController::class, 'show'])
    ->middleware(PageHtmlController::permission('show'))
    ->args(PageHtmlController::showArgs());

// GET /wp-json/mcpmate/v1/post-structure?post_id=
Route::get('post-structure', [PostStructureController::class, 'show'])
    ->middleware(PostStructureController::permission('show'))
    ->args(PostStructureController::showArgs());

// WP-CLI-style commands, run in PHP (no shell). Each command checks its own
// capability and must be enabled by a site admin. The plugin's commands
// only read; MCPMate Pro adds write and destructive ones, which wait for
// approval unless dry_run is true.

// POST /wp-json/mcpmate/v1/cli { command, dry_run }
Route::post('cli', [CliController::class, 'run'])
    ->middleware(CliController::permission('run'))
    ->args(CliController::runArgs());

// Approvals: destructive requests wait for a site admin in wp-admin. The
// requester polls with the token from the 202 response; there is no REST
// route that approves or rejects.

// POST /wp-json/mcpmate/v1/approvals/{id}/status { token }
Route::post('approvals/{id}/status', [ApprovalsController::class, 'status'])
    ->middleware(ApprovalsController::permission('status'))
    ->args(ApprovalsController::statusArgs());

// Database inspection (read-only, no SQL from the client). Needs
// manage_options and a site admin's opt-in; protected columns are never
// returned and secret option/meta values are masked. Reads are audited.

// GET /wp-json/mcpmate/v1/db/tables
Route::get('db/tables', [DbController::class, 'tables'])->middleware(DbController::permission('tables'));

// GET /wp-json/mcpmate/v1/db/tables/{table}/schema
Route::get('db/tables/{table}/schema', [DbController::class, 'schema'])
    ->middleware(DbController::permission('schema'))
    ->args(DbController::tableArgs());

// GET /wp-json/mcpmate/v1/db/tables/{table}/rows?columns[]=&where[0][column]=&where[0][op]=&where[0][value]=&order_by=&order=&limit=&offset=
Route::get('db/tables/{table}/rows', [DbController::class, 'rows'])
    ->middleware(DbController::permission('rows'))
    ->args(DbController::rowsArgs());

// Abilities other plugins register (WordPress 6.9+ Abilities API; 501 on
// older versions). Only abilities exposed over REST, each enabled by a site
// admin; the ability's own schema and permission callback are checked.
// Without MCPMate Pro only read abilities run; with it, destructive ones
// wait for approval.

// GET /wp-json/mcpmate/v1/abilities?search=&category=&page=&per_page=
Route::get('abilities', [AbilitiesController::class, 'index'])
    ->middleware(AbilitiesController::permission('index'))
    ->args(AbilitiesController::indexArgs());

// POST /wp-json/mcpmate/v1/abilities/run { name, input }
Route::post('abilities/run', [AbilitiesController::class, 'run'])
    ->middleware(AbilitiesController::permission('run'))
    ->args(AbilitiesController::runArgs());

// Gutenberg blocks (read-only): the registered block types, and a check of
// block markup before anything saves it. Needs edit_posts.

// GET /wp-json/mcpmate/v1/blocks/types?namespace=&search=&page=&per_page=
Route::get('blocks/types', [BlocksController::class, 'types'])
    ->middleware(BlocksController::permission('types'))
    ->args(BlocksController::typesArgs());

// POST /wp-json/mcpmate/v1/blocks/validate { content }
Route::post('blocks/validate', [BlocksController::class, 'validate'])
    ->middleware(BlocksController::permission('validate'))
    ->args(BlocksController::validateArgs());
