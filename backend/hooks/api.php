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
 */

use McpMate\HTTP\Router\Route;
use McpMate\HTTP\Controllers\Api\AbilitiesController;
use McpMate\HTTP\Controllers\Api\ApprovalsController;
use McpMate\HTTP\Controllers\Api\AuditLogController;
use McpMate\HTTP\Controllers\Api\BlocksController;
use McpMate\HTTP\Controllers\Api\CliController;
use McpMate\HTTP\Controllers\Api\DbController;
use McpMate\HTTP\Controllers\Api\ElementorController;
use McpMate\HTTP\Controllers\Api\MediaController;
use McpMate\HTTP\Controllers\Api\PageHtmlController;
use McpMate\HTTP\Controllers\Api\PingController;
use McpMate\HTTP\Controllers\Api\PostStructureController;
use McpMate\HTTP\Controllers\Api\ProxyController;
use McpMate\HTTP\Controllers\Api\SiteInfoController;
use McpMate\HTTP\Controllers\Api\StatsController;
use McpMate\HTTP\Controllers\Api\ThemeDraftController;
use McpMate\HTTP\Controllers\Api\ToolsController;
use McpMate\Services\Elementor\ElementorPages;

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
// capability and must be enabled by a site admin; destructive ones wait for
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

// Media uploads. Needs upload_files and a site admin's opt-in. Remote URLs
// must be public http(s) addresses (SSRF checks, pinned connection); the
// type is read from the file; SVG needs manage_options and is sanitized.
// Media can be attached to drafts only.

// POST /wp-json/mcpmate/v1/media { url | data, filename, title, alt, caption, post_id, featured, insert }
Route::post('media', [MediaController::class, 'upload'])
    ->middleware(MediaController::permission('upload'))
    ->args(MediaController::uploadArgs());

// Theme drafts: the AI edits a copy of the active theme. Needs edit_themes
// (withheld by DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS) and a site admin's
// opt-in. File paths stay inside the draft folder (realpath, no links,
// allowlisted extensions); PHP is syntax-checked and linted before saving.
// Publish and rollback also need switch_themes and always wait for approval.

// GET /wp-json/mcpmate/v1/theme-draft
Route::get('theme-draft', [ThemeDraftController::class, 'index'])->middleware(ThemeDraftController::permission('index'));

// POST /wp-json/mcpmate/v1/theme-draft
Route::post('theme-draft', [ThemeDraftController::class, 'create'])->middleware(ThemeDraftController::permission('create'));

// POST /wp-json/mcpmate/v1/theme-draft/publish { draft_id, dry_run }
Route::post('theme-draft/publish', [ThemeDraftController::class, 'publish'])
    ->middleware(ThemeDraftController::permission('publish'))
    ->args(ThemeDraftController::publishArgs());

// POST /wp-json/mcpmate/v1/theme-draft/rollback { backup_id, dry_run }
Route::post('theme-draft/rollback', [ThemeDraftController::class, 'rollback'])
    ->middleware(ThemeDraftController::permission('rollback'))
    ->args(ThemeDraftController::rollbackArgs());

// GET /wp-json/mcpmate/v1/theme-draft/{id}/files?path=&recursive=
Route::get('theme-draft/{id}/files', [ThemeDraftController::class, 'files'])
    ->middleware(ThemeDraftController::permission('files'))
    ->args(ThemeDraftController::filesArgs());

// GET /wp-json/mcpmate/v1/theme-draft/{id}/file?path=&line=
Route::get('theme-draft/{id}/file', [ThemeDraftController::class, 'read'])
    ->middleware(ThemeDraftController::permission('read'))
    ->args(ThemeDraftController::readArgs());

// PUT /wp-json/mcpmate/v1/theme-draft/{id}/file { path, content }
Route::put('theme-draft/{id}/file', [ThemeDraftController::class, 'write'])
    ->middleware(ThemeDraftController::permission('write'))
    ->args(ThemeDraftController::writeArgs());

// DELETE /wp-json/mcpmate/v1/theme-draft/{id}/file?path=
Route::delete('theme-draft/{id}/file', [ThemeDraftController::class, 'delete'])
    ->middleware(ThemeDraftController::permission('delete'))
    ->args(ThemeDraftController::deleteArgs());

// POST /wp-json/mcpmate/v1/theme-draft/{id}/file/replace { path, find, replace }
Route::post('theme-draft/{id}/file/replace', [ThemeDraftController::class, 'replace'])
    ->middleware(ThemeDraftController::permission('replace'))
    ->args(ThemeDraftController::replaceArgs());

// POST /wp-json/mcpmate/v1/theme-draft/{id}/preview { path, expires_in }
Route::post('theme-draft/{id}/preview', [ThemeDraftController::class, 'preview'])
    ->middleware(ThemeDraftController::permission('preview'))
    ->args(ThemeDraftController::previewArgs());

// Core posts and pages through an allowlisted route table (CoreRoutes), run as
// the current user with rest_do_request(). Needs edit_posts and a site
// admin's opt-in. Drafts only, trash only, no meta, no _embed.

// POST /wp-json/mcpmate/v1/proxy { method, route, query, body }
Route::post('proxy', [ProxyController::class, 'proxy'])
    ->middleware(ProxyController::permission('proxy'))
    ->args(ProxyController::proxyArgs());

// Abilities other plugins register (WordPress 6.9+ Abilities API; 501 on
// older versions). Only abilities exposed over REST, each enabled by a site
// admin; the ability's own schema and permission callback are checked, and
// destructive ones wait for approval.

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

// Elementor pages, only while Elementor is active. Read and written through
// Elementor's document API (never _elementor_data directly), so its CSS and
// caches stay right. Needs edit_posts, edit_post on the page and a site
// admin's opt-in. Only draft and pending posts are written; new pages are
// drafts. Trees are validated first (registered, allowed widgets; settings
// that match their controls) and never saved with errors.
if (ElementorPages::isAvailable()) {
    // Registered before elementor/{post_id}, which would also match "widgets".
    // GET /wp-json/mcpmate/v1/elementor/widgets?search=&category=&name=&tab=&page=&per_page=
    Route::get('elementor/widgets', [ElementorController::class, 'widgets'])
        ->middleware(ElementorController::permission('widgets'))
        ->args(ElementorController::widgetsArgs());

    // POST /wp-json/mcpmate/v1/elementor { title, post_type, elements | copy_from, page_settings, dry_run }
    Route::post('elementor', [ElementorController::class, 'create'])
        ->middleware(ElementorController::permission('create'))
        ->args(ElementorController::createArgs());

    // GET /wp-json/mcpmate/v1/elementor/{post_id}?element_id=
    Route::get('elementor/{post_id}', [ElementorController::class, 'show'])
        ->middleware(ElementorController::permission('show'))
        ->args(ElementorController::showArgs());

    // POST /wp-json/mcpmate/v1/elementor/{post_id} { elements | patches, title, page_settings, expected_version, dry_run }
    Route::post('elementor/{post_id}', [ElementorController::class, 'save'])
        ->middleware(ElementorController::permission('save'))
        ->args(ElementorController::saveArgs());
}
