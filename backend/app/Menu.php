<?php

namespace MCPMate;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Views\Body;

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
     * The MCPMate mark (two rounded strokes forming an M, docs/ui.md §6.8,
     * frontend/src/resource/brand/mcpmate-mark-mono.svg) in one colour, so
     * WordPress's svg-painter tints its `fill` for each admin colour scheme.
     * frontend/src/components/ui/Logo.tsx draws the same paths.
     */
    private const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="80 84 352 352">'
        . '<path fill="black" d="M432 388L432 132A44 44 0 0 0 354.06 104L222.06 264A44 44 0 0 0 289.94 320L344 254.47L344 388A44 44 0 0 0 432 388Z"/>'
        . '<path fill="black" d="M80 388L80 132A44 44 0 0 1 157.94 104L289.94 264A44 44 0 0 1 222.06 320L168 254.47L168 388A44 44 0 0 1 80 388Z"/>'
        . '</svg>';

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
