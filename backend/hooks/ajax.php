<?php

/**
 * AJAX route definitions.
 *
 * All routes in the middleware group require a valid nonce and admin privileges.
 * The $router variable is provided by HookProvider before this file is included.
 */

use McpMate\HTTP\Router\Route;
use McpMate\HTTP\Controllers\AbilitySettingsController;
use McpMate\HTTP\Controllers\AccessController;
use McpMate\HTTP\Controllers\ApprovalReviewController;
use McpMate\HTTP\Controllers\AuditLogPurgeController;
use McpMate\HTTP\Controllers\ChangesController;
use McpMate\HTTP\Controllers\CliCommandsController;
use McpMate\HTTP\Controllers\ConnectedAppsController;
use McpMate\HTTP\Controllers\ConnectionSettingsController;
use McpMate\HTTP\Controllers\CoreProxySettingsController;
use McpMate\HTTP\Controllers\DbInspectController;
use McpMate\HTTP\Controllers\ElementorSettingsController;
use McpMate\HTTP\Controllers\MediaUploadController;
use McpMate\HTTP\Controllers\OAuthClientsController;
use McpMate\HTTP\Controllers\OnboardingController;
use McpMate\HTTP\Controllers\PreferencesController;
use McpMate\HTTP\Controllers\SkillsController;
use McpMate\HTTP\Controllers\StockPhotosController;
use McpMate\HTTP\Controllers\ThemeDraftSettingsController;

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

    // Media upload switch for POST /media
    Route::post('media-upload', [MediaUploadController::class, 'show']);
    Route::post('media-upload/update', [MediaUploadController::class, 'update']);

    // Theme drafts switch for the /theme-draft REST routes and preview links
    Route::post('theme-drafts', [ThemeDraftSettingsController::class, 'show']);
    Route::post('theme-drafts/update', [ThemeDraftSettingsController::class, 'update']);

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

    // Settings: logging, retention, change history capture, uninstall
    Route::post('preferences', [PreferencesController::class, 'show']);
    Route::post('preferences/update', [PreferencesController::class, 'update']);

    // Audit log: run the retention purge now (by age only), after showing how many rows it removes
    Route::post('audit-log/purge-preview', [AuditLogPurgeController::class, 'preview']);
    Route::post('audit-log/purge', [AuditLogPurgeController::class, 'purge']);

    // Change history: what writes made through the plugin changed (snapshots stay in wp-admin)
    Route::post('changes/list', [ChangesController::class, 'index']);
    Route::post('changes/show', [ChangesController::class, 'show']);

    // Core content switch for POST /proxy
    Route::post('core-proxy', [CoreProxySettingsController::class, 'show']);
    Route::post('core-proxy/update', [CoreProxySettingsController::class, 'update']);

    // Elementor switch for the /elementor REST routes
    Route::post('elementor', [ElementorSettingsController::class, 'show']);
    Route::post('elementor/update', [ElementorSettingsController::class, 'update']);

    // Stock photos for the MCP tools: the site owner's Unsplash key (write-only) and app name
    Route::post('stock-photos', [StockPhotosController::class, 'show']);
    Route::post('stock-photos/update', [StockPhotosController::class, 'update']);

    // Skills AI apps saved for this site's users: list, delete
    Route::post('skills/list', [SkillsController::class, 'index']);
    Route::post('skills/delete', [SkillsController::class, 'delete']);
})->middleware('nonce', 'isAdmin');
