<?php

namespace McpMate\Views;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Hooks\Hooks;
use McpMate\Utils\Capabilities;

/**
 * Registers the admin sidebar menu and enqueues page-level assets.
 */
final class Layout
{
    public function __construct()
    {
        Hooks::addAction('in_admin_header', [$this, 'removeAdminNotices']);
        Hooks::addAction('admin_menu', [$this, 'registerMenus']);
        Hooks::addAction('admin_enqueue_scripts', [new Head(), 'enqueueAssets'], 0);
        Hooks::addAction('admin_init', [$this, 'sendFrameHeaders']);
    }

    /**
     * The plugin's pages (the AI-app approval screen and the Approvals page
     * among them) are never shown in a frame: no clickjacking an Approve.
     */
    public function sendFrameHeaders(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only compares the page slug.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if ($page !== Config::SLUG || headers_sent()) {
            return;
        }

        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
    }

    /**
     * Register admin menu and submenu items from the Menu definition.
     */
    public function registerMenus(): void
    {
        $menus = Hooks::applyFilter(
            Config::withPrefix('admin_sidebar_menu'),
            Config::get('SIDE_BAR_MENU')
        );

        global $submenu;

        foreach ($menus as $menu) {
            if (!isset($menu['capability']) || !Capabilities::check($menu['capability'])) {
                continue;
            }

            if ($menu['type'] === 'menu') {
                add_menu_page(
                    $menu['title'],
                    $menu['name'],
                    $menu['capability'],
                    $menu['slug'],
                    $menu['callback'],
                    $menu['icon'],
                    $menu['position']
                );
            } else {
                // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- add_submenu_page() cannot register hash-route URLs; appending to $submenu is the supported workaround.
                $submenu[$menu['parent']][] = [
                    $menu['name'],
                    $menu['capability'],
                    $menu['url'] ?? 'admin.php?page=' . $menu['slug'],
                ];
            }
        }
    }

    /**
     * Suppress all admin notices on our plugin pages for a clean UI.
     */
    public function removeAdminNotices(): void
    {
        global $plugin_page;

        if (empty($plugin_page) || !str_contains($plugin_page, Config::SLUG)) {
            return;
        }

        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
    }
}
