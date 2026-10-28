<?php

namespace McpMate;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Views\Body;

/**
 * Defines the WordPress admin sidebar menu structure.
 *
 * Returns an array of menu/submenu definitions consumed by Layout::registerMenus().
 * Each entry follows this shape:
 *
 * [
 *   'type'       => 'menu' | 'submenu',
 *   'name'       => 'Visible label',
 *   'capability' => 'manage_options',
 *   'slug'       => 'page slug',
 *   // menu-only:
 *   'title'      => 'Browser tab title',
 *   'callback'   => callable,
 *   'icon'       => 'dashicons-* or data URI',
 *   'position'   => 25,
 *   // submenu-only:
 *   'parent'     => 'parent menu slug',
 * ]
 *
 * One item, no submenu (docs/ui.md §3.4): the admin app's own top bar holds
 * the navigation, and old `#/<route>` links redirect in frontend/src/AppRoutes.tsx.
 */
final class Menu
{
    /**
     * The McpMate mark (two linked rounded rectangles, docs/ui.md §6.8) in
     * one colour, so WordPress's svg-painter tints its `fill` for each admin
     * colour scheme. frontend/src/components/ui/Logo.tsx draws the same paths.
     */
    private const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><g transform="rotate(-45 12 12)">'
        . '<path fill="black" fill-rule="evenodd" d="M6.5 6.75h4a5.25 5.25 0 0 1 0 10.5h-4a5.25 5.25 0 0 1 0-10.5zm0 2.5h4a2.75 2.75 0 0 1 0 5.5h-4a2.75 2.75 0 0 1 0-5.5z"/>'
        . '<path fill="black" fill-rule="evenodd" d="M13.5 6.75h4a5.25 5.25 0 0 1 0 10.5h-4a5.25 5.25 0 0 1 0-10.5zm0 2.5h4a2.75 2.75 0 0 1 0 5.5h-4a2.75 2.75 0 0 1 0-5.5z"/>'
        . '</g></svg>';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSideBarMenu(Body $body): array
    {
        return [
            'Home' => self::homeMenu($body),
        ];
    }

    /**
     * The menu icon as a data URI.
     */
    public static function icon(): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- add_menu_page() takes an SVG icon as a base64 data URI.
        return 'data:image/svg+xml;base64,' . base64_encode(self::ICON_SVG);
    }

    // ──────────────────────────────────────────────
    //  Menu builders
    // ──────────────────────────────────────────────

    private static function homeMenu(Body $body): array
    {
        $title = Config::title();

        return [
            'type'       => 'menu',
            'title'      => $title,
            'name'       => $title,
            'capability' => 'manage_options',
            'slug'       => Config::SLUG,
            'callback'   => [$body, 'render'],
            'icon'       => self::icon(),
            'position'   => '25',
        ];
    }
}
