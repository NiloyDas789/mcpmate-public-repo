<?php

/**
 * AJAX route definitions.
 *
 * All routes in the middleware group require a valid nonce and admin privileges.
 * The $router variable is provided by HookProvider before this file is included.
 * MCPMate Pro's feature switches are declared in its own file, inside the
 * same group (Feature::registerAjaxRoutes()).
 */

use MCPMate\Extensions\Features;
use MCPMate\HTTP\Router\Route;
use MCPMate\HTTP\Controllers\AbilitySettingsController;
use MCPMate\HTTP\Controllers\AccessController;
use MCPMate\HTTP\Controllers\ApprovalReviewController;
use MCPMate\HTTP\Controllers\AuditLogPurgeController;
use MCPMate\HTTP\Controllers\ChangesController;
use MCPMate\HTTP\Controllers\CliCommandsController;
use MCPMate\HTTP\Controllers\ConnectedAppsController;
use MCPMate\HTTP\Controllers\ConnectionSettingsController;
use MCPMate\HTTP\Controllers\DbInspectController;
use MCPMate\HTTP\Controllers\OAuthClientsController;
use MCPMate\HTTP\Controllers\OnboardingController;
use MCPMate\HTTP\Controllers\PreferencesController;
use MCPMate\HTTP\Controllers\SkillsController;
use MCPMate\HTTP\Controllers\StockPhotosController;
use MCPMate\HTTP\Controllers\UninstallReviewController;

if (!defined('ABSPATH')) {
    exit;
}

if (!headers_sent()) {
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

    $mcpmate_request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
    if ($mcpmate_request_method === 'OPTIONS') {
        status_header(200);
        exit;
    }
}

// ──────────────────────────────────────────────
//  Protected routes (nonce + admin check)
//  NOTE: All routes use POST because WordPress admin-ajax.php
//        processes requests via POST with FormData.
//
//  The frontend calls these with ajaxRequest('<path>'), which sends
//  action=MCPMATE_<path> to admin-ajax.php.
// ──────────────────────────────────────────────

Route::group(function (): void {
    // First-run setup wizard (#/setup): progress, and the site checks
    Route::post('onboarding/state', [OnboardingController::class, 'state']);
    Route::post('onboarding/update', [OnboardingController::class, 'update']);
    Route::post('onboarding/checks', [OnboardingController::class, 'checks']);

    // CLI commands enabled for POST /cli
    Route::post('cli-commands', [CliCommandsController::class, 'index']);
    Route::post('cli-commands/update', [CliCommandsController::class, 'update']);

    // Abilities enabled for POST /abilities/run
    Route::post('abilities', [AbilitySettingsController::class, 'index']);
    Route::post('abilities/update', [AbilitySettingsController::class, 'update']);

    // Approval requests: review, approve (runs the operation once), reject
    Route::post('approvals', [ApprovalReviewController::class, 'index']);
    Route::post('approvals/show', [ApprovalReviewController::class, 'show']);
    Route::post('approvals/approve', [ApprovalReviewController::class, 'approve']);
    Route::post('approvals/reject', [ApprovalReviewController::class, 'reject']);

    // Database inspection switch for the /db REST routes
    Route::post('db-inspect', [DbInspectController::class, 'show']);
    Route::post('db-inspect/update', [DbInspectController::class, 'update']);

    // The MCP URL, and the AI-app approval screen (approve creates a grant and its code)
    Route::post('connection/status', [ConnectionSettingsController::class, 'status']);
    Route::post('connection/approve', [ConnectionSettingsController::class, 'approve']);
    Route::post('connection/reject', [ConnectionSettingsController::class, 'reject']);

    // Registered OAuth clients: list, revoke (with every grant of it), allow again
    Route::post('clients/list', [OAuthClientsController::class, 'index']);
    Route::post('clients/revoke', [OAuthClientsController::class, 'revoke']);
    Route::post('clients/restore', [OAuthClientsController::class, 'restore']);

    // Connected AI apps: list grants, revoke one or all
    Route::post('grants/list', [ConnectedAppsController::class, 'index']);
    Route::post('grants/revoke', [ConnectedAppsController::class, 'revoke']);
    Route::post('grants/revoke-all', [ConnectedAppsController::class, 'revokeAll']);
    Route::post('grants/update', [ConnectedAppsController::class, 'update']);

    // Pause AI access: every MCP call is refused until resumed
    Route::post('access', [AccessController::class, 'show']);
    Route::post('access/pause', [AccessController::class, 'pause']);
    Route::post('access/resume', [AccessController::class, 'resume']);

    // Read mode: every AI app may read only until it is turned off
    Route::post('read-mode', [AccessController::class, 'readMode']);
    Route::post('read-mode/on', [AccessController::class, 'readModeOn']);
    Route::post('read-mode/off', [AccessController::class, 'readModeOff']);

    // Settings: logging, retention, change history capture, uninstall
    Route::post('preferences', [PreferencesController::class, 'show']);
    Route::post('preferences/update', [PreferencesController::class, 'update']);

    // Audit log: run the retention purge now (by age only), after showing how many rows it removes
    Route::post('audit-log/purge-preview', [AuditLogPurgeController::class, 'preview']);
    Route::post('audit-log/purge', [AuditLogPurgeController::class, 'purge']);

    // Before you uninstall: what is deleted, what stays, and a JSON copy first
    Route::post('uninstall/summary', [UninstallReviewController::class, 'summary']);
    Route::post('uninstall/export', [UninstallReviewController::class, 'export']);

    // Change history: what writes made through the plugin changed (snapshots stay in wp-admin)
    Route::post('changes/list', [ChangesController::class, 'index']);
    Route::post('changes/show', [ChangesController::class, 'show']);

    // Stock photos for the MCP tools: the site owner's Unsplash key (write-only) and app name
    Route::post('stock-photos', [StockPhotosController::class, 'show']);
    Route::post('stock-photos/update', [StockPhotosController::class, 'update']);

    // Tools → Skills: saved skills (view, edit, trash, restore, delete a trashed one) and the playbooks
    Route::post('skills/list', [SkillsController::class, 'index']);
    Route::post('skills/show', [SkillsController::class, 'show']);
    Route::post('skills/update', [SkillsController::class, 'update']);
    Route::post('skills/trash', [SkillsController::class, 'trash']);
    Route::post('skills/restore', [SkillsController::class, 'restore']);
    Route::post('skills/delete', [SkillsController::class, 'delete']);

    // MCPMate Pro's settings routes (while its license is valid)
    foreach (Features::all() as $mcpmate_feature) {
        $mcpmate_feature->registerAjaxRoutes();
    }
})->middleware('nonce', 'isAdmin');
